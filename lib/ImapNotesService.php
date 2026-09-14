<?php

class ImapNotesService
{
    private $storage;
    private $content;
    private $message_factory;
    private $resolver;
    private $conflicts;
    private $identity_resolver;

    public function __construct(
        ImapNotesStorageInterface $storage,
        ImapNotesContent $content,
        ImapNotesMessage $message_factory,
        ImapNotesRevisionResolver $resolver,
        ImapNotesConflictResolver $conflicts,
        ImapNotesIdentityResolverInterface $identity_resolver
    ) {
        $this->storage = $storage;
        $this->content = $content;
        $this->message_factory = $message_factory;
        $this->resolver = $resolver;
        $this->conflicts = $conflicts;
        $this->identity_resolver = $identity_resolver;
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

        $has_existing_identity = !empty($state['uid']) || !empty($state['note_key']) || !empty($state['logical_uuid']);
        $conflict_state = $has_existing_identity ? $this->storage->checkCurrentRevision($folder, $state) : ['status' => 'ok'];
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
                'selected' => array_merge($this->blankNote($folder), $state, ['note_key' => '', 'title' => $title, 'body_text' => $body]),
                'conflict' => $conflict_state['current'] ?? null,
            ];
        }

        $logical_uuid = !empty($state['logical_uuid']) && empty($decision['copy'])
            ? $state['logical_uuid']
            : ImapNotesMessage::uuidV4();
        if ($this->submittedBodyStartsWithTitle($title, $body) && $this->shouldNormalizeCompatibleBody($conflict_state['current'] ?? null)) {
            $body = $this->content->normalizeImportedEditableBody($title, $body, true, true);
        }
        $storage_text = $this->content->composeStorageBodyText($title, $body);
        $html = $this->content->textToSafeHtml($storage_text);
        $created_at = $this->parseCreatedDate((string) ($state['created_at'] ?? ''));
        $from = $this->identity_resolver->resolveFromHeader();
        $message = $this->message_factory->createRevision($logical_uuid, $title, $html, $from, $created_at);
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
            if (!empty($cleanup['error'])) {
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

                return [
                    'status' => 'error',
                    'message' => $cleanup['error'],
                    'selected' => $selected,
                ];
            }
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
            $selected['cleanup_pending_target_uid'] = (string) (($cleanup['entry']['uid'] ?? '') ?: ($state['uid'] ?? ''));
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
        if (($result['status'] ?? '') === 'conflict') {
            $selected_key = !empty($result['current']['note_key']) ? $result['current']['note_key'] : null;
            $view = $this->view($selected_key);
            if (!empty($result['current'])) {
                $view['selected'] = $result['current'];
                $view['conflict'] = $result['current'];
            }

            return [
                'status' => 'conflict',
                'message' => $result['message'] ?? 'This note changed on the server before your delete completed.',
                'selected' => $view['selected'],
                'notes' => $view['notes'],
                'folder' => $view['folder'],
                'conflict' => $view['conflict'] ?? null,
            ];
        }

        if (!empty($result['error'])) {
            $view = $this->view($state['note_key'] ?? null);

            return [
                'status' => 'error',
                'message' => $result['error'],
                'selected' => $view['selected'],
                'notes' => $view['notes'],
                'folder' => $view['folder'],
            ];
        }

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
                : ($result['message'] ?? 'Cleanup is still pending on the server.'),
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
            'logical_uuid' => '',
            'message_id' => '',
            'updated_at' => '',
            'created_at' => '',
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
            'logical_uuid' => $input['logical_uuid'] ?? '',
            'message_id' => $input['message_id'] ?? '',
            'updated_at' => $input['updated_at'] ?? '',
            'created_at' => $input['created_at'] ?? '',
            'fingerprint' => $input['fingerprint'] ?? '',
            'read_only' => !empty($input['read_only']),
            'plugin_managed' => !empty($input['plugin_managed']),
            'legacy_apple' => !empty($input['legacy_apple']),
        ];
    }

    private function parseCreatedDate($created_at)
    {
        if ($created_at === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($created_at);
        } catch (Exception $e) {
            return null;
        }
    }

    private function shouldNormalizeCompatibleBody($note)
    {
        return !empty($note) && (!empty($note['plugin_managed']) || !empty($note['legacy_apple']));
    }

    private function submittedBodyStartsWithTitle($title, $body)
    {
        $normalized_title = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $this->content->normalizePlainText((string) $title)));
        if ($normalized_title === '') {
            return false;
        }

        foreach (preg_split('/\n/', $this->content->normalizePlainText((string) $body)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $this->content->normalizePlainText($line))) === $normalized_title;
        }

        return false;
    }
}
