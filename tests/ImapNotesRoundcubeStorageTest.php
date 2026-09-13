<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('rcube_message')) {
    class rcube_message
    {
        public static $messages = [];

        public $headers;
        public $attachments = [];
        private $part;
        private $body = '';

        public function __construct($uid, $folder)
        {
            $data = self::$messages[$folder][(string) $uid] ?? null;
            if (!$data) {
                $this->headers = null;
                return;
            }

            $this->headers = new ImapNotesRoundcubeStorageTestHeaders($data['headers'] ?? []);
            $this->attachments = $data['attachments'] ?? [];
            $this->body = (string) ($data['body'] ?? '');
            if (!empty($data['mimetype'])) {
                $this->part = (object) [
                    'mime_id' => '1',
                    'mimetype' => $data['mimetype'],
                ];
            }
        }

        public function first_text_part(&$part)
        {
            $part = $this->part;
        }

        public function get_part_body($mime_id)
        {
            return $this->body;
        }
    }
}

class ImapNotesRoundcubeStorageTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        rcube_message::$messages = [];
    }

    public function testTamperedPostedUidCannotDeleteDifferentMessage()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '2', $uuid, '<note-2@example.invalid>', '2026-09-12T13:00:00Z');

        $result = $storage->deleteRevision('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '2', $uuid),
            'uid' => '999',
            'uidvalidity' => '22',
            'logical_uuid' => $uuid,
            'message_id' => '<note-2@example.invalid>',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => $this->fingerprint('Note 2', 'Body 2'),
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
        $this->assertSame([], $this->fakeStorage($storage)->move_message_calls);
        $this->assertSame([], $this->fakeStorage($storage)->expunge_message_calls);
    }

    public function testNoteKeyPointingToDifferentMailboxIsRejectedForDelete()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '2', $uuid, '<note-2@example.invalid>', '2026-09-12T13:00:00Z');

        $result = $storage->deleteRevision('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Archive', '2', $uuid),
            'uid' => '2',
            'uidvalidity' => '22',
            'logical_uuid' => $uuid,
            'message_id' => '<note-2@example.invalid>',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => $this->fingerprint('Note 2', 'Body 2'),
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
    }

    public function testStaleDeleteIsBlockedWhenNewerLogicalRevisionIsActive()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '2', $uuid, '<old@example.invalid>', '2026-09-12T13:00:00Z', 'Old note', 'Body old');
        $this->seedNoteMessage('Notes', '3', $uuid, '<new@example.invalid>', '2026-09-12T13:05:00Z', 'New note', 'Body new');
        $this->fakeStorage($storage)->search_map['HEADER X-Universally-Unique-Identifier "11111111-1111-4111-8111-111111111111" UNDELETED'] = ['2', '3'];

        $result = $storage->deleteRevision('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '2', $uuid),
            'uid' => '2',
            'uidvalidity' => '22',
            'logical_uuid' => $uuid,
            'message_id' => '<old@example.invalid>',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => $this->fingerprint('Old note', 'Body old'),
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('3', $result['current']['uid']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
    }

    public function testUidvalidityMismatchBlocksDeferredCleanupWithoutMutation()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '3', $uuid, '<current@example.invalid>', '2026-09-12T13:05:00Z');
        $_SESSION['imap_notes_deferred_cleanup']['Notes']['2'] = [
            'uid' => '2',
            'uidvalidity' => '21',
            'logical_uuid' => $uuid,
            'message_id' => '<old@example.invalid>',
        ];

        $result = $storage->retryCleanup('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '3', $uuid),
            'cleanup_pending_target_uid' => '2',
        ]);

        $this->assertTrue($result['cleanup_pending']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
    }

    public function testMissingDeferredCleanupEntryDoesNotReportCleaned()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '3', $uuid, '<current@example.invalid>', '2026-09-12T13:05:00Z');

        $result = $storage->retryCleanup('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '3', $uuid),
            'cleanup_pending_target_uid' => '2',
        ]);

        $this->assertTrue($result['cleanup_pending']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
    }

    public function testDeferredCleanupMessageIdMismatchBlocksMutation()
    {
        $storage = $this->newStorage();
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '2', $uuid, '<changed@example.invalid>', '2026-09-12T13:00:00Z');
        $this->seedNoteMessage('Notes', '3', $uuid, '<current@example.invalid>', '2026-09-12T13:05:00Z');
        $_SESSION['imap_notes_deferred_cleanup']['Notes']['2'] = [
            'uid' => '2',
            'uidvalidity' => '22',
            'logical_uuid' => $uuid,
            'message_id' => '<old@example.invalid>',
        ];

        $result = $storage->retryCleanup('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '3', $uuid),
            'cleanup_pending_target_uid' => '2',
        ]);

        $this->assertTrue($result['cleanup_pending']);
        $this->assertSame([], $this->fakeStorage($storage)->set_flag_calls);
    }

    public function testValidDeferredCleanupUsesOnlyExpectedUid()
    {
        $storage = $this->newStorage(['uidplus' => true]);
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->seedNoteMessage('Notes', '2', $uuid, '<old@example.invalid>', '2026-09-12T13:00:00Z');
        $this->seedNoteMessage('Notes', '3', $uuid, '<current@example.invalid>', '2026-09-12T13:05:00Z');
        $_SESSION['imap_notes_deferred_cleanup']['Notes']['2'] = [
            'uid' => '2',
            'uidvalidity' => '22',
            'logical_uuid' => $uuid,
            'message_id' => '<old@example.invalid>',
        ];

        $result = $storage->retryCleanup('Notes', [
            'note_key' => ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '3', $uuid),
            'cleanup_pending_target_uid' => '2',
        ]);

        $this->assertFalse($result['cleanup_pending']);
        $this->assertSame([['2', 'DELETED', 'Notes']], $this->fakeStorage($storage)->set_flag_calls);
        $this->assertSame([['2', 'Notes', false]], $this->fakeStorage($storage)->expunge_message_calls);
    }

    public function testMalformedUuidDoesNotEnterRawImapHeaderSearch()
    {
        $storage = $this->newStorage();

        $result = $storage->checkCurrentRevision('Notes', [
            'uid' => '999',
            'uidvalidity' => '22',
            'logical_uuid' => "not-a-uuid\r\nUID 1",
            'message_id' => '<tampered@example.invalid>',
        ]);

        $this->assertSame('missing', $result['status']);
        $this->assertSame([['Notes', 'UID 999']], $this->fakeStorage($storage)->search_calls);
    }

    public function testEnsureFolderUsesRoundcubeNamespaceFolderTransform()
    {
        $storage = $this->newStorage(['folder_exists' => []]);
        $this->fakeStorage($storage)->mod_folder_result = 'INBOX/Notes';

        $folder = $storage->ensureFolder();

        $this->assertSame('INBOX/Notes', $folder);
        $this->assertSame([['Notes', 'in']], $this->fakeStorage($storage)->mod_folder_calls);
        $this->assertSame([['INBOX/Notes', true]], $this->fakeStorage($storage)->create_folder_calls);
    }

    public function testAppleMessageWithoutSubjectUsesFirstLineAsTitleWithoutStrippingBody()
    {
        $storage = $this->newStorage();
        rcube_message::$messages['Notes']['9'] = [
            'headers' => [
                'message-id' => '<nosubject@example.invalid>',
                'x-uniform-type-identifier' => 'com.apple.mail-note',
            ],
            'mimetype' => 'text/plain',
            'body' => "First line\n\nSecond line",
            'attachments' => [],
        ];

        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '9'));

        $this->assertSame('First line', $note['title']);
        $this->assertSame("First line\n\nSecond line", $note['body_text']);
    }

    public function testPluginManagedMessageRepairsRepeatedTitlePrefixInEditableBody()
    {
        $storage = $this->newStorage();
        rcube_message::$messages['Notes']['10'] = [
            'headers' => [
                'subject' => 'Próba',
                'message-id' => '<repeated@example.invalid>',
                'x-roundcube-note-version' => '1',
                'x-uniform-type-identifier' => 'com.apple.mail-note',
            ],
            'mimetype' => 'text/plain',
            'body' => "Próba\n\nPróba\n\nEz egy próba jegyzet",
            'attachments' => [],
        ];

        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '10'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame('Ez egy próba jegyzet', $note['body_text']);
    }

    public function testAppleMarkedMessageRepairsRepeatedTitlePrefixWithoutPluginMarker()
    {
        $storage = $this->newStorage();
        rcube_message::$messages['Notes']['12'] = [
            'headers' => [
                'subject' => 'Próba',
                'message-id' => '<legacy-repeated@example.invalid>',
                'x-uniform-type-identifier' => 'com.apple.mail-note',
            ],
            'mimetype' => 'text/html',
            'body' => "<html><body>\n<p>Próba</p>\n<p>Próba</p>\n<p>Ez egy próba jegyzet</p>\n</body></html>",
            'attachments' => [],
        ];

        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '12'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame('Ez egy próba jegyzet', $note['body_text']);
    }

    public function testAppleMarkedBodyIsNotStrippedWhenFirstLineDoesNotMatchSubject()
    {
        $storage = $this->newStorage();
        rcube_message::$messages['Notes']['11'] = [
            'headers' => [
                'subject' => 'Próba',
                'message-id' => '<mismatch@example.invalid>',
                'x-uniform-type-identifier' => 'com.apple.mail-note',
            ],
            'mimetype' => 'text/plain',
            'body' => "Másik első sor\n\nEz egy próba jegyzet",
            'attachments' => [],
        ];

        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '11'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame("Másik első sor\n\nEz egy próba jegyzet", $note['body_text']);
    }

    public function testGenericImportedMessageWithMatchingFirstLineIsNotStripped()
    {
        $storage = $this->newStorage();
        rcube_message::$messages['Notes']['13'] = [
            'headers' => [
                'subject' => 'Próba',
                'message-id' => '<generic-matching@example.invalid>',
            ],
            'mimetype' => 'text/html',
            'body' => "<html><body>\n<p>Próba</p>\n<p>Ez egy próba jegyzet</p>\n</body></html>",
            'attachments' => [],
        ];

        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '13'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame("Próba\n\nEz egy próba jegyzet", $note['body_text']);
    }

    private function newStorage(array $options = [])
    {
        $fake_storage = new ImapNotesRoundcubeStorageTestFakeStorage($options);
        $rcmail = new ImapNotesRoundcubeStorageTestFakeRcmail($fake_storage, new ImapNotesRoundcubeStorageTestFakeConfig([
            'imap_notes_folder' => 'Notes',
            'trash_mbox' => $options['trash_mbox'] ?? '',
        ]));

        return new ImapNotesRoundcubeStorage($rcmail, new ImapNotesContent());
    }

    private function fakeStorage(ImapNotesRoundcubeStorage $storage)
    {
        $reflection = new ReflectionProperty($storage, 'rcmail');
        $reflection->setAccessible(true);
        $rcmail = $reflection->getValue($storage);

        return $rcmail->get_storage();
    }

    private function seedNoteMessage($folder, $uid, $uuid, $message_id, $updated_at, $subject = 'Note 2', $body = 'Body 2')
    {
        rcube_message::$messages[$folder][(string) $uid] = [
            'headers' => [
                'subject' => $subject,
                'message-id' => $message_id,
                'x-roundcube-note-version' => '1',
                'x-uniform-type-identifier' => 'com.apple.mail-note',
                'x-universally-unique-identifier' => $uuid,
                'x-roundcube-note-updated' => $updated_at,
                'internaldate' => date('D, d M Y H:i:s O', strtotime($updated_at)),
            ],
            'mimetype' => 'text/plain',
            'body' => $body,
            'attachments' => [],
        ];
    }

    private function fingerprint($title, $body)
    {
        return (new ImapNotesContent())->fingerprint($title, $body);
    }
}

class ImapNotesRoundcubeStorageTestHeaders
{
    private $headers;
    public $internaldate;

    public function __construct(array $headers)
    {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            if ($name === 'internaldate') {
                $this->internaldate = $value;
                continue;
            }

            $this->headers[strtolower($name)] = $value;
        }
    }

    public function get($name)
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}

class ImapNotesRoundcubeStorageTestFakeSearchResult
{
    private $uids;

    public function __construct(array $uids)
    {
        $this->uids = array_values(array_map('strval', $uids));
    }

    public function get()
    {
        return $this->uids;
    }

    public function exists($uid)
    {
        return in_array((string) $uid, $this->uids, true);
    }
}

class ImapNotesRoundcubeStorageTestFakeStorage
{
    public $search_map = [];
    public $search_calls = [];
    public $set_flag_calls = [];
    public $move_message_calls = [];
    public $expunge_message_calls = [];
    public $create_folder_calls = [];
    public $mod_folder_calls = [];
    public $mod_folder_result = '';
    public $uidplus = false;
    public $folder_exists = ['Notes' => true];
    public $set_flag_result = true;
    public $move_message_result = false;
    public $expunge_message_result = true;
    private $folder_data = ['Notes' => ['UIDVALIDITY' => '22']];

    public function __construct(array $options = [])
    {
        if (array_key_exists('uidplus', $options)) {
            $this->uidplus = (bool) $options['uidplus'];
        }

        if (array_key_exists('folder_exists', $options)) {
            $this->folder_exists = $options['folder_exists'];
        }

        if (!empty($options['folder_data'])) {
            $this->folder_data = $options['folder_data'];
        }
    }

    public function folder_data($folder)
    {
        return $this->folder_data[$folder] ?? ['UIDVALIDITY' => '22'];
    }

    public function search_once($folder, $criteria)
    {
        $this->search_calls[] = [$folder, $criteria];

        if (array_key_exists($criteria, $this->search_map)) {
            return new ImapNotesRoundcubeStorageTestFakeSearchResult($this->search_map[$criteria]);
        }

        if (strpos($criteria, 'UID ') === 0) {
            $uid = trim(substr($criteria, 4));
            $exists = isset(rcube_message::$messages[$folder][(string) $uid]) ? [$uid] : [];

            return new ImapNotesRoundcubeStorageTestFakeSearchResult($exists);
        }

        if ($criteria === 'ALL UNDELETED') {
            return new ImapNotesRoundcubeStorageTestFakeSearchResult(array_keys(rcube_message::$messages[$folder] ?? []));
        }

        return new ImapNotesRoundcubeStorageTestFakeSearchResult([]);
    }

    public function folder_exists($folder)
    {
        return !empty($this->folder_exists[$folder]);
    }

    public function create_folder($folder, $subscribe)
    {
        $this->create_folder_calls[] = [$folder, $subscribe];

        return true;
    }

    public function set_flag($uid, $flag, $folder)
    {
        $this->set_flag_calls[] = [(string) $uid, $flag, $folder];

        return $this->set_flag_result;
    }

    public function move_message($uid, $target, $folder)
    {
        $this->move_message_calls[] = [(string) $uid, $target, $folder];

        return $this->move_message_result;
    }

    public function get_capability($capability)
    {
        return $capability === 'UIDPLUS' ? $this->uidplus : false;
    }

    public function expunge_message($uid, $folder, $force)
    {
        $this->expunge_message_calls[] = [(string) $uid, $folder, (bool) $force];

        return $this->expunge_message_result;
    }

    public function mod_folder($folder, $mode)
    {
        $this->mod_folder_calls[] = [$folder, $mode];

        return $this->mod_folder_result ?: $folder;
    }
}

class ImapNotesRoundcubeStorageTestFakeConfig
{
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }
}

class ImapNotesRoundcubeStorageTestFakeRcmail
{
    public $config;
    private $storage;

    public function __construct($storage, $config)
    {
        $this->storage = $storage;
        $this->config = $config;
    }

    public function get_storage()
    {
        return $this->storage;
    }
}
