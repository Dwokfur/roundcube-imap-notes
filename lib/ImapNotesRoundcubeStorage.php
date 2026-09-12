<?php

class ImapNotesRoundcubeStorage implements ImapNotesStorageInterface
{
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
        $notes = [];

        foreach ($uids as $uid) {
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
            return [
                'status' => 'conflict',
                'current' => $this->findCurrentByLogicalUuid($folder, $state['logical_uuid']),
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

        $same_updated = empty($state['updated_at']) || $state['updated_at'] === ($current['updated_at'] ?? '');
        $same_fingerprint = empty($state['fingerprint']) || $state['fingerprint'] === ($current['fingerprint'] ?? '');

        if ($same_updated && $same_fingerprint) {
            return ['status' => 'ok', 'current' => $current];
        }

        return ['status' => 'conflict', 'current' => $current];
    }

    public function appendRevision($folder, array $message, array $note_data)
    {
        $storage = $this->rcmail->get_storage();
        $raw = $message['raw'];
        $result = $storage->save_message($folder, $raw, '', false, ['SEEN'], $message['date']);
        $uid = is_numeric($result) ? (string) $result : null;

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
                'updated_at' => $message['updated_at'],
                'title' => $note_data['title'],
                'fingerprint' => $note_data['fingerprint'],
            ],
        ];
    }

    public function retireRevision($folder, array $state, array $new_revision)
    {
        $storage = $this->rcmail->get_storage();
        $uid = (string) $state['uid'];
        if ($uid === '' || $uid === (string) $new_revision['uid']) {
            return ['cleanup_pending' => false];
        }

        if (!$storage->set_flag($uid, 'DELETED', $folder)) {
            return ['cleanup_pending' => true];
        }

        if ($storage->get_capability('UIDPLUS')) {
            return ['cleanup_pending' => !$storage->expunge_message($uid, $folder, false)];
        }

        return ['cleanup_pending' => true];
    }

    public function deleteRevision($folder, array $state)
    {
        $storage = $this->rcmail->get_storage();
        $uid = (string) $state['uid'];
        $trash = (string) $this->rcmail->config->get('trash_mbox');

        if ($uid === '') {
            return ['cleanup_pending' => false];
        }

        if ($trash && $storage->move_message($uid, $trash, $folder)) {
            return ['cleanup_pending' => false];
        }

        if (!$storage->set_flag($uid, 'DELETED', $folder)) {
            return ['cleanup_pending' => true];
        }

        if ($storage->get_capability('UIDPLUS')) {
            return ['cleanup_pending' => !$storage->expunge_message($uid, $folder, false)];
        }

        return ['cleanup_pending' => true];
    }

    public function retryCleanup($folder, array $state)
    {
        $storage = $this->rcmail->get_storage();
        $uid = (string) ($state['cleanup_pending_target_uid'] ?? $state['uid'] ?? '');

        if ($uid === '') {
            return ['cleanup_pending' => false];
        }

        if (!$storage->get_capability('UIDPLUS')) {
            return ['cleanup_pending' => true];
        }

        return ['cleanup_pending' => !$storage->expunge_message($uid, $folder, false)];
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
        $title = $this->content->deriveTitle((string) $message->headers->get('subject'), $body_text);

        return [
            'note_key' => self::encodeNoteKey($folder, $uid, $logical_uuid),
            'mailbox' => $folder,
            'uid' => (string) $uid,
            'uidvalidity' => (string) (($folder_data['UIDVALIDITY'] ?? '') ?: ''),
            'modseq' => (string) (($folder_data['HIGHESTMODSEQ'] ?? '') ?: ''),
            'logical_uuid' => $logical_uuid,
            'message_id' => (string) $message->headers->get('message-id', false),
            'updated_at' => $updated_at,
            'internal_date' => (string) $message->headers->get('date', false),
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
        if (!$logical_uuid) {
            return null;
        }

        $search = 'HEADER X-Universally-Unique-Identifier ' . $this->imapQuotedString($logical_uuid) . ' UNDELETED';
        $result = $this->rcmail->get_storage()->search_once($folder, $search);
        $uids = method_exists($result, 'get') ? $result->get() : [];
        if (empty($uids)) {
            return null;
        }

        rsort($uids, SORT_NUMERIC);

        foreach ($uids as $uid) {
            $note = $this->buildNoteFromMessage($folder, $uid);
            if ($note) {
                return $note;
            }
        }

        return null;
    }

    private function lookupAppendedUid($folder, array $message)
    {
        $criteria = [
            'HEADER Message-ID ' . $this->imapQuotedString($message['message_id']),
            'HEADER X-Roundcube-Note-Updated ' . $this->imapQuotedString($message['updated_at']),
            'HEADER X-Universally-Unique-Identifier ' . $this->imapQuotedString($message['logical_uuid']),
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

        $personal = $storage->get_namespace('personal');
        $prefix = '';
        if (is_array($personal) && !empty($personal[0][0])) {
            $prefix = $personal[0][0];
        }

        if ($prefix && strpos($configured, $prefix) !== 0) {
            return $prefix . $configured;
        }

        return $configured;
    }

    private function imapQuotedString($value)
    {
        return '"' . addcslashes((string) $value, '\\"') . '"';
    }
}
