<?php

class ImapNotesRoundcubeStorage implements ImapNotesStorageInterface
{
    private const SESSION_DEFERRED_CLEANUP = 'imap_notes_deferred_cleanup';
    private const SESSION_LEGACY_HIDDEN_UIDS = 'imap_notes_hidden_uids';

    private $rcmail;
    private $content;

    public function __construct($rcmail, ImapNotesContent $content)
    {
        $this->rcmail = $rcmail;
        $this->content = $content;
    }

    public function ensureFolder()
    {
        $storage = $this->rcmail->get_storage();
        $configured = trim((string) $this->rcmail->config->get('imap_notes_folder', 'Notes'));
        $folder = $this->resolveFolderName($configured);

        if ($storage->folder_exists($folder)) {
            return $folder;
        }

        if ($storage->create_folder($folder, true)) {
            return $folder;
        }

        throw new RuntimeException(sprintf('Unable to access or create the configured notes mailbox: %s', $configured));
    }

    public function listRevisions($folder)
    {
        $storage = $this->rcmail->get_storage();
        $index = $storage->search_once($folder, 'ALL UNDELETED');
        $uids = method_exists($index, 'get') ? $index->get() : [];
        $hidden = $this->hiddenDeferredUids($folder);
        $notes = [];

        foreach ($uids as $uid) {
            if (isset($hidden[(string) $uid])) {
                continue;
            }

            $note = $this->buildNoteFromMessage($folder, $uid);
            if ($note) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    public function loadRevision($folder, $note_key)
    {
        $data = self::decodeNoteKey($note_key);
        if (empty($data['uid']) || (!empty($data['mailbox']) && $data['mailbox'] !== $folder)) {
            return null;
        }

        return $this->buildNoteFromMessage($folder, $data['uid']);
    }

    public function checkCurrentRevision($folder, array $state)
    {
        $storage = $this->rcmail->get_storage();
        $folder_data = $storage->folder_data($folder);
        $current_uidvalidity = isset($folder_data['UIDVALIDITY']) ? (string) $folder_data['UIDVALIDITY'] : '';

        if (!empty($state['uidvalidity']) && $current_uidvalidity !== '' && $state['uidvalidity'] !== $current_uidvalidity) {
            $current = $this->findCurrentByLogicalUuid($folder, $state['logical_uuid']);

            return [
                'status' => $current ? 'conflict' : 'missing',
                'current' => $current,
            ];
        }

        $uid = (string) $state['uid'];
        if ($uid === '') {
            return ['status' => 'ok'];
        }

        $uid_exists = $storage->search_once($folder, 'UID ' . $uid);
        if (!method_exists($uid_exists, 'exists') || !$uid_exists->exists((int) $uid)) {
            $current = $this->findCurrentByLogicalUuid($folder, $state['logical_uuid']);

            return [
                'status' => $current ? 'conflict' : 'missing',
                'current' => $current,
            ];
        }

        $current = $this->buildNoteFromMessage($folder, $uid);
        if (!$current) {
            return ['status' => 'missing'];
        }

        $same_logical_uuid = empty($state['logical_uuid']) || $state['logical_uuid'] === ($current['logical_uuid'] ?? '');
        $same_message_id = empty($state['message_id']) || $state['message_id'] === ($current['message_id'] ?? '');
        $same_updated = empty($state['updated_at']) || $state['updated_at'] === ($current['updated_at'] ?? '');
        $same_fingerprint = empty($state['fingerprint']) || $state['fingerprint'] === ($current['fingerprint'] ?? '');

        if ($same_logical_uuid && $same_message_id && $same_updated && $same_fingerprint) {
            return ['status' => 'ok', 'current' => $current];
        }

        return ['status' => 'conflict', 'current' => $current];
    }

    public function appendRevision($folder, array $message, array $note_data)
    {
        $storage = $this->rcmail->get_storage();
        $raw = $message['raw'];
        $result = $storage->save_message($folder, $raw, '', false, ['SEEN'], $message['date']);
        $uid = null;

        if (is_numeric($result)) {
            $uid = (string) $result;
        } elseif (is_array($result) && !empty($result['uid']) && ctype_digit((string) $result['uid'])) {
            $uid = (string) $result['uid'];
        } elseif (is_object($result) && !empty($result->uid) && ctype_digit((string) $result->uid)) {
            $uid = (string) $result->uid;
        }

        if (!$uid && $result) {
            $uid = $this->lookupAppendedUid($folder, $message);
        }

        if (!$result || !$uid) {
            return [
                'success' => false,
                'message' => 'The note save result is uncertain. Reload the note before retrying.',
            ];
        }

        return [
            'success' => true,
            'revision' => [
                'note_key' => self::encodeNoteKey($folder, $uid, $message['logical_uuid']),
                'uid' => $uid,
                'logical_uuid' => $message['logical_uuid'],
                'message_id' => $message['message_id'],
                'updated_at' => $message['updated_at'],
                'title' => $note_data['title'],
                'fingerprint' => $note_data['fingerprint'],
            ],
        ];
    }

    public function retireRevision($folder, array $state, array $new_revision)
    {
        $storage = $this->rcmail->get_storage();
        $validated = $this->validateMutationTarget($folder, $state, [
            'message' => 'The previous revision no longer matches the note you were editing. Reload before retrying.',
            'current' => !empty($new_revision['logical_uuid']) ? $this->findCurrentByLogicalUuid($folder, $new_revision['logical_uuid']) : null,
        ]);

        if (($validated['status'] ?? '') !== 'ok') {
            return [
                'cleanup_pending' => false,
                'error' => $validated['message'],
            ];
        }

        $current = $validated['note'];
        $uid = (string) $current['uid'];
        if ($uid === '' || $uid === (string) $new_revision['uid']) {
            return ['cleanup_pending' => false];
        }

        if (!empty($current['logical_uuid'])) {
            $active = $this->findCurrentByLogicalUuid($folder, $current['logical_uuid']);
            if ($active && (string) $active['uid'] !== (string) $new_revision['uid']) {
                return [
                    'cleanup_pending' => false,
                    'error' => 'The previous revision changed on the server before it could be retired. Reload before retrying.',
                ];
            }
        }

        if (!$storage->set_flag($uid, 'DELETED', $folder)) {
            return [
                'cleanup_pending' => false,
                'error' => 'The previous revision could not be retired safely.',
            ];
        }

        if ($storage->get_capability('UIDPLUS')) {
            $pending = !$storage->expunge_message($uid, $folder, false);
            if ($pending) {
                $this->hideDeferredCleanup($folder, $current);
            } else {
                $this->clearDeferredCleanup($folder, $uid);
            }

            return ['cleanup_pending' => $pending, 'entry' => $this->deferredCleanupEntries($folder)[$uid] ?? null];
        }

        $this->hideDeferredCleanup($folder, $current);

        return ['cleanup_pending' => true, 'entry' => $this->deferredCleanupEntries($folder)[$uid] ?? null];
    }

    public function deleteRevision($folder, array $state)
    {
        $storage = $this->rcmail->get_storage();
        $validated = $this->validateMutationTarget($folder, $state, [
            'message' => 'This note changed on the server before your delete completed. Reload before deleting.',
            'require_current' => true,
        ]);

        if (($validated['status'] ?? '') !== 'ok') {
            return $validated;
        }

        $current = $validated['note'];
        $uid = (string) $current['uid'];
        $trash = trim((string) $this->rcmail->config->get('trash_mbox'));
        if ($uid === '') {
            return ['status' => 'deleted', 'cleanup_pending' => false];
        }

        if ($trash !== '') {
            $trash = $this->resolveFolderName($trash);
        }

        if ($trash && $storage->move_message($uid, $trash, $folder)) {
            $this->clearDeferredCleanup($folder, $uid);
            return ['status' => 'deleted', 'cleanup_pending' => false];
        }

        if (!$storage->set_flag($uid, 'DELETED', $folder)) {
            return [
                'status' => 'error',
                'cleanup_pending' => false,
                'error' => 'The note could not be deleted safely.',
            ];
        }

        if ($storage->get_capability('UIDPLUS')) {
            $pending = !$storage->expunge_message($uid, $folder, false);
            if ($pending) {
                $this->hideDeferredCleanup($folder, $current);
            } else {
                $this->clearDeferredCleanup($folder, $uid);
            }

            return [
                'status' => $pending ? 'cleanup_pending' : 'deleted',
                'cleanup_pending' => $pending,
                'entry' => $this->deferredCleanupEntries($folder)[$uid] ?? null,
            ];
        }

        $this->hideDeferredCleanup($folder, $current);

        return [
            'status' => 'cleanup_pending',
            'cleanup_pending' => true,
            'entry' => $this->deferredCleanupEntries($folder)[$uid] ?? null,
        ];
    }

    public function retryCleanup($folder, array $state)
    {
        $storage = $this->rcmail->get_storage();
        $entry = $this->selectDeferredCleanupEntry($folder, $state);
        if (!$entry) {
            return [
                'status' => 'cleanup_pending',
                'cleanup_pending' => true,
                'message' => 'Deferred cleanup information is incomplete. Reload and review before retrying.',
            ];
        }

        $folder_data = $storage->folder_data($folder);
        $uidvalidity = (string) (($folder_data['UIDVALIDITY'] ?? '') ?: '');
        if ($entry['uidvalidity'] !== '' && $uidvalidity !== '' && $entry['uidvalidity'] !== $uidvalidity) {
            $this->hideDeferredCleanup($folder, $entry);

            return [
                'status' => 'cleanup_pending',
                'cleanup_pending' => true,
                'message' => 'Deferred cleanup no longer matches the current mailbox state. Reload and review before retrying.',
            ];
        }

        $uid = (string) $entry['uid'];
        if (!$storage->get_capability('UIDPLUS')) {
            $this->hideDeferredCleanup($folder, $entry);

            return ['status' => 'cleanup_pending', 'cleanup_pending' => true];
        }

        $note = $this->buildNoteFromMessage($folder, $uid);
        if (!$note || !$this->matchesDeferredCleanupEntry($note, $entry) || empty($note['plugin_managed'])) {
            $this->hideDeferredCleanup($folder, $entry);

            return [
                'status' => 'cleanup_pending',
                'cleanup_pending' => true,
                'message' => 'Deferred cleanup no longer matches the original revision. Reload and review before retrying.',
            ];
        }

        if (!$storage->set_flag($uid, 'DELETED', $folder)) {
            $this->hideDeferredCleanup($folder, $entry);
            return ['status' => 'cleanup_pending', 'cleanup_pending' => true];
        }

        $pending = !$storage->expunge_message($uid, $folder, false);
        if ($pending) {
            $this->hideDeferredCleanup($folder, $note);
        } else {
            $this->clearDeferredCleanup($folder, $uid);
        }

        return [
            'status' => $pending ? 'cleanup_pending' : 'cleaned',
            'cleanup_pending' => $pending,
        ];
    }

    public static function encodeNoteKey($folder, $uid, $logical_uuid = null)
    {
        return rtrim(strtr(base64_encode(json_encode([
            'mailbox' => $folder,
            'uid' => (string) $uid,
            'logical_uuid' => (string) $logical_uuid,
        ])), '+/', '-_'), '=');
    }

    public static function decodeNoteKey($note_key)
    {
        if (!$note_key) {
            return [];
        }

        $padding = strlen($note_key) % 4;
        if ($padding) {
            $note_key .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($note_key, '-_', '+/'), true);
        $data = json_decode((string) $decoded, true);

        return is_array($data) ? $data : [];
    }

    private function buildNoteFromMessage($folder, $uid)
    {
        $storage = $this->rcmail->get_storage();
        $message = new rcube_message($uid, $folder, true);
        if (empty($message->headers)) {
            $this->clearDeferredCleanup($folder, $uid);
            return null;
        }

        $part = null;
        $message->first_text_part($part);
        $has_attachments = !empty($message->attachments);
        $mimetype = $part && !empty($part->mimetype) ? strtolower($part->mimetype) : '';
        $body = $part ? $message->get_part_body($part->mime_id) : '';
        $plugin_managed = (string) $message->headers->get('x-roundcube-note-version', false) !== '';
        $legacy_apple = strtolower((string) $message->headers->get('x-uniform-type-identifier', false)) === 'com.apple.mail-note';
        $logical_uuid = trim((string) $message->headers->get('x-universally-unique-identifier', false));
        $updated_at = trim((string) $message->headers->get('x-roundcube-note-updated', false));
        $created_at = trim((string) $message->headers->get('x-mail-created-date', false));
        $body_text = '';
        $body_html = '';
        $read_only = false;
        $read_only_reason = '';

        if ($mimetype === 'text/plain') {
            $body_text = $this->content->normalizePlainText($body);
            $body_html = $this->content->textToSafeHtml($body_text);
        } elseif ($mimetype === 'text/html') {
            $body_html = $this->content->sanitizeHtml($body);
            $body_text = $this->content->htmlToText($body);
        } elseif (in_array($mimetype, ['text/markdown', 'text/x-markdown'], true)) {
            $body_text = $this->content->normalizePlainText($body);
            $body_html = $this->content->textToSafeHtml($body_text);
        } elseif ($part) {
            $body_text = $this->content->normalizePlainText($body);
            $body_html = $this->content->textToSafeHtml($body_text);
            $read_only = true;
            $read_only_reason = 'This note uses an unsupported text subtype and is shown read-only.';
        } else {
            $read_only = true;
            $read_only_reason = 'This message has no safely editable text body.';
        }

        if ($has_attachments) {
            $read_only = true;
            $read_only_reason = 'This message has attachments or extra MIME parts that v1 will not rewrite safely.';
        }

        $folder_data = $storage->folder_data($folder);
        $subject = (string) $message->headers->get('subject');
        $title = $this->content->deriveTitle($subject, $body_text);
        $body_text = $this->content->normalizeImportedEditableBody(
            $title,
            $body_text,
            $plugin_managed || $legacy_apple
        );
        if ($created_at === '') {
            $created_at = (string) ($message->headers->internaldate ?? '');
        }

        return [
            'note_key' => self::encodeNoteKey($folder, $uid, $logical_uuid),
            'mailbox' => $folder,
            'uid' => (string) $uid,
            'uidvalidity' => (string) (($folder_data['UIDVALIDITY'] ?? '') ?: ''),
            'logical_uuid' => $logical_uuid,
            'message_id' => (string) $message->headers->get('message-id', false),
            'updated_at' => $updated_at,
            'created_at' => $created_at,
            'internal_date' => (string) ($message->headers->internaldate ?? ''),
            'title' => $title,
            'preview' => $this->content->previewText($body_text),
            'body_text' => $body_text,
            'body_html' => $body_html,
            'fingerprint' => $this->content->fingerprint($title, $body_text),
            'read_only' => $read_only,
            'read_only_reason' => $read_only_reason,
            'cleanup_pending' => false,
            'cleanup_pending_target_uid' => '',
            'plugin_managed' => $plugin_managed,
            'legacy_apple' => $legacy_apple,
            'imported' => !$plugin_managed,
        ];
    }

    private function findCurrentByLogicalUuid($folder, $logical_uuid)
    {
        if (!$this->isStrictUuid($logical_uuid)) {
            return null;
        }

        $quoted = $this->imapQuotedString($logical_uuid);
        if ($quoted === null) {
            return null;
        }

        $search = 'HEADER X-Universally-Unique-Identifier ' . $quoted . ' UNDELETED';
        $result = $this->rcmail->get_storage()->search_once($folder, $search);
        $uids = method_exists($result, 'get') ? $result->get() : [];
        $hidden = $this->hiddenDeferredUids($folder);
        if (empty($uids)) {
            return null;
        }

        $revisions = [];
        foreach ($uids as $uid) {
            if (isset($hidden[(string) $uid])) {
                continue;
            }

            $note = $this->buildNoteFromMessage($folder, $uid);
            if ($note) {
                $revisions[] = $note;
            }
        }

        if (empty($revisions)) {
            return null;
        }

        $resolver = new ImapNotesRevisionResolver();
        $selected = $resolver->selectDisplayNotes($revisions);

        return $selected['active'][0] ?? null;
    }

    private function lookupAppendedUid($folder, array $message)
    {
        $message_id = $this->imapQuotedString($message['message_id']);
        $updated_at = $this->imapQuotedString($message['updated_at']);
        $logical_uuid = $this->imapQuotedString($message['logical_uuid']);
        if ($message_id === null || $updated_at === null || $logical_uuid === null) {
            return null;
        }

        $criteria = [
            'HEADER Message-ID ' . $message_id,
            'HEADER X-Roundcube-Note-Updated ' . $updated_at,
            'HEADER X-Universally-Unique-Identifier ' . $logical_uuid,
            'UNDELETED',
        ];
        $result = $this->rcmail->get_storage()->search_once($folder, implode(' ', $criteria));
        $uids = method_exists($result, 'get') ? $result->get() : [];

        if (count($uids) === 1) {
            return (string) $uids[0];
        }

        return null;
    }

    private function resolveFolderName($configured)
    {
        $storage = $this->rcmail->get_storage();
        if ($storage->folder_exists($configured)) {
            return $configured;
        }

        if (method_exists($storage, 'mod_folder')) {
            $resolved = $storage->mod_folder($configured, 'in');
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return $configured;
    }

    private function imapQuotedString($value)
    {
        if (preg_match('/[\x00-\x1F\x7F]/', (string) $value)) {
            return null;
        }

        return '"' . addcslashes((string) $value, '\\"') . '"';
    }

    private function hiddenDeferredUids($folder)
    {
        return array_fill_keys(array_keys($this->deferredCleanupEntries($folder)), true);
    }

    private function hideDeferredCleanup($folder, array $note)
    {
        $entry = $this->normalizeDeferredCleanupEntry($note);
        if (!$entry) {
            return;
        }

        if (empty($_SESSION[self::SESSION_DEFERRED_CLEANUP]) || !is_array($_SESSION[self::SESSION_DEFERRED_CLEANUP])) {
            $_SESSION[self::SESSION_DEFERRED_CLEANUP] = [];
        }

        if (empty($_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder]) || !is_array($_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder])) {
            $_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder] = [];
        }

        $_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder][$entry['uid']] = $entry;
    }

    private function clearDeferredCleanup($folder, $uid)
    {
        if (!empty($_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder]) && is_array($_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder])) {
            unset($_SESSION[self::SESSION_DEFERRED_CLEANUP][$folder][(string) $uid]);
        }

        if (!empty($_SESSION[self::SESSION_LEGACY_HIDDEN_UIDS][$folder]) && is_array($_SESSION[self::SESSION_LEGACY_HIDDEN_UIDS][$folder])) {
            $_SESSION[self::SESSION_LEGACY_HIDDEN_UIDS][$folder] = array_values(array_filter(
                $_SESSION[self::SESSION_LEGACY_HIDDEN_UIDS][$folder],
                function ($value) use ($uid) {
                    return (string) $value !== (string) $uid;
                }
            ));
        }
    }

    private function deferredCleanupEntries($folder)
    {
        $entries = [];
        $current = $_SESSION[self::SESSION_DEFERRED_CLEANUP] ?? [];
        if (!empty($current[$folder]) && is_array($current[$folder])) {
            foreach ($current[$folder] as $uid => $entry) {
                $normalized = $this->normalizeDeferredCleanupEntry($entry, $uid);
                if ($normalized) {
                    $entries[$normalized['uid']] = $normalized;
                }
            }
        }

        $legacy = $_SESSION[self::SESSION_LEGACY_HIDDEN_UIDS] ?? [];
        if (!empty($legacy[$folder]) && is_array($legacy[$folder])) {
            foreach ($legacy[$folder] as $value) {
                $normalized = $this->normalizeDeferredCleanupEntry($value);
                if ($normalized && empty($entries[$normalized['uid']])) {
                    $entries[$normalized['uid']] = $normalized;
                }
            }
        }

        return $entries;
    }

    private function normalizeDeferredCleanupEntry($entry, $fallback_uid = null)
    {
        if (is_scalar($entry)) {
            $entry = ['uid' => (string) $entry];
        }

        if (!is_array($entry)) {
            return null;
        }

        $uid = (string) ($entry['uid'] ?? $fallback_uid ?? '');
        if (!preg_match('/^[0-9]+$/', $uid)) {
            return null;
        }

        return [
            'uid' => $uid,
            'uidvalidity' => (string) ($entry['uidvalidity'] ?? ''),
            'logical_uuid' => trim((string) ($entry['logical_uuid'] ?? '')),
            'message_id' => (string) ($entry['message_id'] ?? ''),
        ];
    }

    private function selectDeferredCleanupEntry($folder, array $state)
    {
        $entries = $this->deferredCleanupEntries($folder);
        if (empty($entries)) {
            return null;
        }

        $current = $this->loadRevision($folder, $state['note_key'] ?? '');
        $logical_uuid = (string) ($current['logical_uuid'] ?? '');
        if ($logical_uuid !== '') {
            $matching = [];
            foreach ($entries as $entry) {
                if ($entry['logical_uuid'] === $logical_uuid && $entry['uid'] !== (string) ($current['uid'] ?? '')) {
                    $matching[] = $entry;
                }
            }

            if (count($matching) === 1) {
                return $matching[0];
            }
        }

        $uid = (string) ($state['cleanup_pending_target_uid'] ?? $state['uid'] ?? '');

        return $entries[$uid] ?? null;
    }

    private function matchesDeferredCleanupEntry(array $note, array $entry)
    {
        if ((string) $note['uid'] !== (string) $entry['uid']) {
            return false;
        }

        if ($entry['message_id'] !== '' && $entry['message_id'] !== (string) ($note['message_id'] ?? '')) {
            return false;
        }

        if ($entry['logical_uuid'] !== '' && $entry['logical_uuid'] !== (string) ($note['logical_uuid'] ?? '')) {
            return false;
        }

        return true;
    }

    private function validateMutationTarget($folder, array $state, array $options = [])
    {
        $message = $options['message'] ?? 'This note changed on the server. Reload before retrying.';
        $note_key = self::decodeNoteKey($state['note_key'] ?? '');
        if (empty($note_key['uid']) || !preg_match('/^[0-9]+$/', (string) $note_key['uid'])) {
            return ['status' => 'conflict', 'message' => $message, 'current' => $options['current'] ?? null];
        }

        if (!empty($note_key['mailbox']) && $note_key['mailbox'] !== $folder) {
            return ['status' => 'conflict', 'message' => $message, 'current' => $options['current'] ?? null];
        }

        $expected_uid = (string) $note_key['uid'];
        if (!empty($state['uid']) && (string) $state['uid'] !== $expected_uid) {
            return ['status' => 'conflict', 'message' => $message, 'current' => $options['current'] ?? null];
        }

        $storage = $this->rcmail->get_storage();
        $folder_data = $storage->folder_data($folder);
        $uidvalidity = (string) (($folder_data['UIDVALIDITY'] ?? '') ?: '');
        if (!empty($state['uidvalidity']) && $uidvalidity !== '' && (string) $state['uidvalidity'] !== $uidvalidity) {
            return [
                'status' => 'conflict',
                'message' => $message,
                'current' => $this->currentRevisionFromState($folder, $state, $options['current'] ?? null),
            ];
        }

        $note = $this->buildNoteFromMessage($folder, $expected_uid);
        if (!$note) {
            return [
                'status' => 'conflict',
                'message' => $message,
                'current' => $this->currentRevisionFromState($folder, $state, $options['current'] ?? null),
            ];
        }

        if (!$this->matchesExpectedRevisionState($note, $state, $note_key)) {
            return [
                'status' => 'conflict',
                'message' => $message,
                'current' => $this->currentRevisionFromState($folder, $state, $options['current'] ?? null),
            ];
        }

        if (!empty($options['require_current']) && !empty($note['logical_uuid'])) {
            $current = $this->findCurrentByLogicalUuid($folder, $note['logical_uuid']);
            if ($current && (string) $current['uid'] !== (string) $note['uid']) {
                return ['status' => 'conflict', 'message' => $message, 'current' => $current];
            }
        }

        return ['status' => 'ok', 'note' => $note];
    }

    private function matchesExpectedRevisionState(array $note, array $state, array $note_key)
    {
        $expected = [
            'uid' => $note_key['uid'] ?? '',
            'logical_uuid' => $note_key['logical_uuid'] ?? '',
            'uidvalidity' => $state['uidvalidity'] ?? '',
            'message_id' => $state['message_id'] ?? '',
            'updated_at' => $state['updated_at'] ?? '',
            'fingerprint' => $state['fingerprint'] ?? '',
        ];

        foreach ($expected as $field => $value) {
            if ($value === '') {
                continue;
            }

            if ((string) $value !== (string) ($note[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function currentRevisionFromState($folder, array $state, $fallback = null)
    {
        $logical_uuid = (string) ($state['logical_uuid'] ?? '');
        if ($logical_uuid !== '') {
            $current = $this->findCurrentByLogicalUuid($folder, $logical_uuid);
            if ($current) {
                return $current;
            }
        }

        return $fallback;
    }

    private function isStrictUuid($value)
    {
        return is_string($value) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
