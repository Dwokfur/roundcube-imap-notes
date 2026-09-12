<?php

class ImapNotesService
{
    private $storage;
    private $content;
    private $message_factory;
    private $resolver;
    private $conflicts;

    public function __construct(
        ImapNotesStorageInterface $storage,
        ImapNotesContent $content,
        ImapNotesMessage $message_factory,
        ImapNotesRevisionResolver $resolver,
        ImapNotesConflictResolver $conflicts
    ) {
        $this->storage = $storage;
        $this->content = $content;
        $this->message_factory = $message_factory;
        $this->resolver = $resolver;
        $this->conflicts = $conflicts;
    }

    public function view($selected_key = null)
    {
        $folder = $this->storage->ensureFolder();
        $resolved = $this->resolver->selectDisplayNotes($this->storage->listRevisions($folder));
        $notes = $resolved['active'];
        $selected = null;

        if ($selected_key) {
            $selected = $this->storage->loadRevision($folder, $selected_key);
        }

        if (!$selected && !empty($notes)) {
            $selected = $this->storage->loadRevision($folder, $notes[0]['note_key']);
        }

        if (!$selected) {
            $selected = $this->blankNote($folder);
        }

        return [
            'folder' => $folder,
            'notes' => $notes,
            'selected' => $selected,
        ];
    }

    public function save(array $input, $fallback_title)
    {
        $folder = $this->storage->ensureFolder();
        $body = $this->content->normalizePlainText($input['body'] ?? '');
        $title = $this->content->deriveTitle($input['title'] ?? '', $body, $fallback_title);
        $state = $this->extractState($input);

        if (!empty($state['uid']) && !empty($state['read_only'])) {
            return [
                'status' => 'error',
                'message' => 'This note is read-only in v1 because safe preservation is not guaranteed.',
                'selected' => array_merge($this->blankNote($folder), $state, ['title' => $title, 'body_text' => $body]),
            ];
        }

        $conflict_state = !empty($state['uid']) ? $this->storage->checkCurrentRevision($folder, $state) : ['status' => 'ok'];
        $decision = $this->conflicts->resolve($conflict_state, $input['conflict_decision'] ?? null);

        if ($decision['status'] === 'reload') {
            return [
                'status' => 'reloaded',
                'selected' => $conflict_state['current'] ?? $this->blankNote($folder),
                'message' => 'Reloaded the current remote revision.',
            ];
        }

        if ($decision['status'] === 'conflict') {
            return [
                'status' => 'conflict',
                'message' => 'This note changed on the server before your save completed.',
                'selected' => array_merge($this->blankNote($folder), $state, ['title' => $title, 'body_text' => $body]),
                'conflict' => $conflict_state['current'] ?? null,
            ];
        }

        $logical_uuid = !empty($state['logical_uuid']) && empty($decision['copy'])
            ? $state['logical_uuid']
            : ImapNotesMessage::uuidV4();
        $html = $this->content->textToSafeHtml($body);
        $message = $this->message_factory->createRevision($logical_uuid, $title, $html);
        $append = $this->storage->appendRevision($folder, $message, [
            'title' => $title,
            'body_text' => $body,
            'fingerprint' => $this->content->fingerprint($title, $body),
        ]);

        if (empty($append['success'])) {
            return [
                'status' => 'error',
                'message' => $append['message'] ?? 'The note could not be saved safely.',
                'selected' => array_merge($this->blankNote($folder), $state, ['title' => $title, 'body_text' => $body]),
            ];
        }

        $cleanup_pending = false;
        if (!empty($state['uid']) && empty($decision['copy'])) {
            $cleanup = $this->storage->retireRevision($folder, $state, $append['revision']);
            $cleanup_pending = !empty($cleanup['cleanup_pending']);
        }

        $selected = $this->storage->loadRevision($folder, $append['revision']['note_key']);
        if (!$selected) {
            $selected = array_merge($this->blankNote($folder), $append['revision'], [
                'mailbox' => $folder,
                'title' => $title,
                'preview' => $this->content->previewText($body),
                'body_text' => $body,
                'body_html' => $html,
                'fingerprint' => $this->content->fingerprint($title, $body),
                'plugin_managed' => true,
                'legacy_apple' => true,
                'imported' => false,
            ]);
        }

        if ($cleanup_pending) {
            $selected['cleanup_pending'] = true;
            $selected['cleanup_pending_target_uid'] = $state['uid'];
        }

        return [
            'status' => empty($decision['copy']) ? 'saved' : 'saved_copy',
            'message' => empty($decision['copy']) ? 'Note saved.' : 'Note saved as a copy.',
            'selected' => $selected,
        ];
    }

    public function delete(array $input)
    {
        $folder = $this->storage->ensureFolder();
        $state = $this->extractState($input);
        $result = $this->storage->deleteRevision($folder, $state);
        $view = $this->view();

        return [
            'status' => !empty($result['cleanup_pending']) ? 'delete_cleanup_pending' : 'deleted',
            'message' => !empty($result['cleanup_pending'])
                ? 'Note hidden; final cleanup is pending.'
                : 'Note deleted.',
            'selected' => $view['selected'],
            'notes' => $view['notes'],
            'folder' => $view['folder'],
        ];
    }

    public function retryCleanup(array $input)
    {
        $folder = $this->storage->ensureFolder();
        $state = $this->extractState($input + ['cleanup_pending_target_uid' => $input['cleanup_pending_target_uid'] ?? '']);
        $state['cleanup_pending_target_uid'] = $input['cleanup_pending_target_uid'] ?? '';
        $result = $this->storage->retryCleanup($folder, $state);
        $selected_key = $input['note_key'] ?? null;
        $view = $this->view($selected_key);

        return [
            'status' => empty($result['cleanup_pending']) ? 'cleaned' : 'cleanup_pending',
            'message' => empty($result['cleanup_pending'])
                ? 'Deferred cleanup completed.'
                : 'Cleanup is still pending on the server.',
            'selected' => $view['selected'],
            'notes' => $view['notes'],
            'folder' => $view['folder'],
        ];
    }

    public function blankNote($folder)
    {
        return [
            'note_key' => '',
            'mailbox' => $folder,
            'uid' => '',
            'uidvalidity' => '',
            'modseq' => '',
            'logical_uuid' => '',
            'updated_at' => '',
            'title' => '',
            'preview' => '',
            'body_text' => '',
            'body_html' => '',
            'fingerprint' => $this->content->fingerprint('', ''),
            'read_only' => false,
            'read_only_reason' => '',
            'cleanup_pending' => false,
            'cleanup_pending_target_uid' => '',
            'plugin_managed' => false,
            'legacy_apple' => false,
            'imported' => false,
        ];
    }

    private function extractState(array $input)
    {
        return [
            'note_key' => $input['note_key'] ?? '',
            'mailbox' => $input['mailbox'] ?? '',
            'uid' => $input['uid'] ?? '',
            'uidvalidity' => $input['uidvalidity'] ?? '',
            'modseq' => $input['modseq'] ?? '',
            'logical_uuid' => $input['logical_uuid'] ?? '',
            'updated_at' => $input['updated_at'] ?? '',
            'fingerprint' => $input['fingerprint'] ?? '',
            'read_only' => !empty($input['read_only']),
        ];
    }
}
