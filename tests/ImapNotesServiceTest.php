<?php

use PHPUnit\Framework\TestCase;

class ImapNotesServiceTest extends TestCase
{
    public function testConflictWithoutDecisionReturnsConflict()
    {
        $service = $this->buildService(['status' => 'conflict', 'current' => ['note_key' => 'remote', 'title' => 'Remote']]);
        $result = $service->save([
            'uid' => '1',
            'uidvalidity' => '22',
            'logical_uuid' => 'abc',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Mine',
            'body' => 'Body',
        ], 'Untitled note');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('Remote', $result['conflict']['title']);
    }

    public function testMissingCurrentRevisionStillAllowsSave()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'missing']);
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->save([
            'uid' => '1',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Mine',
            'body' => 'Body',
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
    }

    public function testConflictCopyCreatesNewLogicalUuid()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'conflict', 'current' => ['note_key' => 'remote', 'title' => 'Remote']]);
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->save([
            'uid' => '1',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Mine',
            'body' => 'Body',
            'conflict_decision' => 'copy',
        ], 'Untitled note');

        $this->assertSame('saved_copy', $result['status']);
        $this->assertNotSame('11111111-1111-4111-8111-111111111111', $storage->last_append['logical_uuid']);
        $this->assertNull($storage->retire_called_with);
    }

    public function testConflictOverwritePreservesLogicalUuidAndRetiresPriorRevision()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'conflict', 'current' => ['note_key' => 'remote', 'title' => 'Remote']]);
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->save([
            'uid' => '1',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Mine',
            'body' => 'Body',
            'conflict_decision' => 'overwrite',
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $storage->last_append['logical_uuid']);
        $this->assertNotNull($storage->retire_called_with);
        $this->assertSame('1', $storage->retire_called_with['state']['uid']);
    }

    public function testDeleteReturnsCleanupPendingWhenFinalRemovalIsDeferred()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $storage->delete_result = ['status' => 'cleanup_pending', 'cleanup_pending' => true];
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->delete([
            'note_key' => 'saved-note',
            'uid' => '2',
        ]);

        $this->assertSame('delete_cleanup_pending', $result['status']);
    }

    public function testDeleteConflictReturnsConflictAndReloadsCurrentRevision()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $storage->delete_result = [
            'status' => 'conflict',
            'message' => 'Reload before deleting.',
            'current' => [
                'note_key' => 'saved-note',
                'title' => 'Remote current',
            ],
        ];
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->delete([
            'note_key' => 'saved-note',
            'uid' => '2',
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('Reload before deleting.', $result['message']);
        $this->assertSame('Remote current', $result['selected']['title']);
        $this->assertSame('Remote current', $result['conflict']['title']);
    }

    public function testRetryCleanupReturnsCleanedStatusAfterSuccess()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $storage->retry_result = ['status' => 'cleaned', 'cleanup_pending' => false];
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver(), new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>'));

        $result = $service->retryCleanup([
            'note_key' => 'saved-note',
            'uid' => '2',
            'cleanup_pending_target_uid' => '1',
        ]);

        $this->assertSame('cleaned', $result['status']);
    }

    public function testBlankNoteIncludesMessageIdButNotModseq()
    {
        $service = $this->buildService(['status' => 'ok']);
        $note = $service->blankNote('Notes');

        $this->assertArrayHasKey('message_id', $note);
        $this->assertArrayNotHasKey('modseq', $note);
    }

    public function testViewReturnsBlankNoteForExplicitNewModeEvenWhenNotesExist()
    {
        $service = $this->buildService(['status' => 'ok']);

        $view = $service->view(null, true);

        $this->assertCount(1, $view['notes']);
        $this->assertSame('', $view['selected']['note_key']);
        $this->assertSame('', $view['selected']['title']);
        $this->assertSame('', $view['selected']['body_text']);
    }

    public function testViewPrefersExplicitSelectedNoteOverNewMode()
    {
        $service = $this->buildService(['status' => 'ok']);

        $view = $service->view('saved-note', true);

        $this->assertSame('saved-note', $view['selected']['note_key']);
        $this->assertSame('Saved', $view['selected']['title']);
    }

    public function testRequestCannotOverrideServerDerivedFromIdentity()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Server Sender <server@example.test>')
        );

        $result = $service->save([
            'title' => 'Próba',
            'body' => 'Ez egy próba jegyzet',
            'from' => 'Attacker <attacker@example.test>',
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertStringContainsString('From: Server Sender <server@example.test>', $storage->last_append['raw']);
        $this->assertStringNotContainsString('attacker@example.test', $storage->last_append['raw']);
    }

    public function testSaveFailsWithoutValidIdentityBeforeAppend()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('invalid', true)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid default identity');
        $service->save([
            'title' => 'Próba',
            'body' => 'Ez egy próba jegyzet',
        ], 'Untitled note');
    }

    public function testSaveWritesTitleThenBlankLineThenBodyInStoredHtml()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $result = $service->save([
            'title' => 'Próba',
            'body' => "Ez egy próba jegyzet\náéíóöőúüű",
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertStringContainsString('<p>Próba</p>', $storage->last_append['html']);
        $this->assertStringContainsString('<p>Ez egy próba jegyzet<br />' . "\n" . 'áéíóöőúüű</p>', $storage->last_append['html']);
    }

    public function testSaveStripsSingleCompatibleTitlePrefixBeforeWrite()
    {
        $existing_note = [
            'note_key' => 'current-note',
            'uid' => '4',
            'mailbox' => 'Notes',
            'title' => 'Próba',
            'body_text' => 'Ez egy próba jegyzet',
            'plugin_managed' => true,
            'legacy_apple' => true,
            'read_only' => false,
        ];
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok', 'current' => $existing_note]);
        $storage->saved_note_exists = false;
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $result = $service->save([
            'note_key' => 'current-note',
            'uid' => '4',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Próba',
            'body' => "Próba\n\nEz egy próba jegyzet",
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Próba</p>'));
        $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Ez egy próba jegyzet</p>'));
        $this->assertSame('Ez egy próba jegyzet', $result['selected']['body_text']);
    }

    public function testSaveRepairsCompatibleRepeatedTitlePrefixesBeforeWriteAndInFallbackSelectedNote()
    {
        $existing_note = [
            'note_key' => 'current-note',
            'uid' => '4',
            'mailbox' => 'Notes',
            'title' => 'Próba',
            'body_text' => 'Ez egy próba jegyzet',
            'plugin_managed' => true,
            'legacy_apple' => true,
            'read_only' => false,
        ];
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok', 'current' => $existing_note]);
        $storage->saved_note_exists = false;
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $result = $service->save([
            'note_key' => 'current-note',
            'uid' => '4',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'title' => 'Próba',
            'body' => "Próba\n\nPróba\n\nEz egy próba jegyzet",
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Próba</p>'));
        $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Ez egy próba jegyzet</p>'));
        $this->assertSame('Ez egy próba jegyzet', $result['selected']['body_text']);
    }

    public function testSaveDoesNotTrustPostedCompatibilityMarkersWithoutServerValidatedCurrentNote()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'missing']);
        $storage->saved_note_exists = false;
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $result = $service->save([
            'note_key' => 'current-note',
            'uid' => '4',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'plugin_managed' => '1',
            'legacy_apple' => '1',
            'title' => 'Próba',
            'body' => "Próba\n\nPróba\n\nEz egy próba jegyzet",
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame(3, substr_count($storage->last_append['html'], '<p>Próba</p>'));
        $this->assertSame("Próba\n\nPróba\n\nEz egy próba jegyzet", $result['selected']['body_text']);
    }

    public function testSaveUsesServerDerivedCompatibilityStateInsteadOfPostedMarkers()
    {
        $generic_note = [
            'note_key' => 'current-note',
            'uid' => '4',
            'mailbox' => 'Notes',
            'title' => 'Próba',
            'body_text' => 'Próba' . "\n\n" . 'Ez egy próba jegyzet',
            'plugin_managed' => false,
            'legacy_apple' => false,
            'read_only' => false,
        ];
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok', 'current' => $generic_note]);
        $storage->saved_note_exists = false;
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $result = $service->save([
            'note_key' => 'current-note',
            'uid' => '4',
            'uidvalidity' => '22',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:00:00Z',
            'fingerprint' => 'old',
            'plugin_managed' => '1',
            'legacy_apple' => '1',
            'title' => 'Próba',
            'body' => "Próba\n\nEz egy próba jegyzet",
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame(2, substr_count($storage->last_append['html'], '<p>Próba</p>'));
        $this->assertSame("Próba\n\nEz egy próba jegyzet", $result['selected']['body_text']);
    }

    public function testNewCompatibleNoteRemainsBlankUntilSaveThenKeepsEditorBodyStableAcrossFourSaveReloadCycles()
    {
        $storage = new ImapNotesRoundTripServiceTestStorage();
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );
        $title = 'Próba';
        $body = 'Ez egy próba jegyzet';

        $blank = $service->view(null, true)['selected'];
        $this->assertSame('', $blank['title']);
        $this->assertSame('', $blank['body_text']);

        $result = $service->save([
            'title' => $title,
            'body' => $body,
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);

        for ($i = 0; $i < 4; $i++) {
            $loaded = $service->view($result['selected']['note_key'])['selected'];
            $this->assertSame($body, $loaded['body_text']);
            $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Próba</p>'));
            $this->assertSame($title . "\n\n" . $body, $storage->persistedStorageText());

            $result = $service->save([
                'note_key' => $loaded['note_key'],
                'mailbox' => $loaded['mailbox'],
                'uid' => $loaded['uid'],
                'uidvalidity' => $loaded['uidvalidity'],
                'logical_uuid' => $loaded['logical_uuid'],
                'message_id' => $loaded['message_id'],
                'updated_at' => $loaded['updated_at'],
                'created_at' => $loaded['created_at'],
                'fingerprint' => $loaded['fingerprint'],
                'plugin_managed' => '1',
                'legacy_apple' => '1',
                'title' => $title,
                'body' => $loaded['body_text'],
            ], 'Untitled note');

            $this->assertSame('saved', $result['status']);
            $this->assertSame($body, $result['selected']['body_text']);
            $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Próba</p>'));
            $this->assertSame($title . "\n\n" . $body, $storage->persistedStorageText());
        }
    }

    public function testCompatibleRoundTripSaveRepairsStoredRepeatedTitlePrefixes()
    {
        $storage = new ImapNotesRoundTripServiceTestStorage([
            'title' => 'Próba',
            'storage_text' => "Próba\n\nPróba\n\nPróba\n\nEz egy próba jegyzet",
            'plugin_managed' => true,
            'legacy_apple' => true,
        ]);
        $service = new ImapNotesService(
            $storage,
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );

        $loaded = $service->view('saved-note')['selected'];
        $this->assertSame('Ez egy próba jegyzet', $loaded['body_text']);

        $result = $service->save([
            'note_key' => $loaded['note_key'],
            'mailbox' => $loaded['mailbox'],
            'uid' => $loaded['uid'],
            'uidvalidity' => $loaded['uidvalidity'],
            'logical_uuid' => $loaded['logical_uuid'],
            'message_id' => $loaded['message_id'],
            'updated_at' => $loaded['updated_at'],
            'created_at' => $loaded['created_at'],
            'fingerprint' => $loaded['fingerprint'],
            'title' => $loaded['title'],
            'body' => $loaded['body_text'],
        ], 'Untitled note');

        $this->assertSame('saved', $result['status']);
        $this->assertSame('Ez egy próba jegyzet', $result['selected']['body_text']);
        $this->assertSame(1, substr_count($storage->last_append['html'], '<p>Próba</p>'));
        $this->assertStringContainsString('<p>Ez egy próba jegyzet</p>', $storage->last_append['html']);
    }

    private function buildService(array $conflict_state)
    {
        return new ImapNotesService(
            new ImapNotesServiceTestStorage($conflict_state),
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesServiceTestIdentityResolver('Tester <tester@example.test>')
        );
    }
}

class ImapNotesServiceTestStorage implements ImapNotesStorageInterface
{
    public $conflict_state;
    public $last_append;
    public $retire_called_with;
    public $delete_result = ['cleanup_pending' => false];
    public $retry_result = ['cleanup_pending' => false];
    public $saved_note_exists = true;

    public function __construct(array $conflict_state)
    {
        $this->conflict_state = $conflict_state;
    }

    public function ensureFolder()
    {
        return 'Notes';
    }

    public function listRevisions($folder)
    {
        return [[
            'note_key' => 'saved-note',
            'uid' => '2',
            'logical_uuid' => '11111111-1111-4111-8111-111111111111',
            'updated_at' => '2026-09-12T13:05:00Z',
            'internal_date' => 'Sat, 12 Sep 2026 13:05:00 +0000',
        ]];
    }

    public function loadRevision($folder, $note_key)
    {
        if ($note_key === 'saved-note' && !$this->saved_note_exists) {
            return null;
        }

        if ($note_key === 'saved-note') {
            $append = $this->last_append ?: [
                'logical_uuid' => '11111111-1111-4111-8111-111111111111',
                'updated_at' => '2026-09-12T13:05:00Z',
                'subject' => 'Saved',
                'html' => '<html><body><p>Body</p></body></html>',
            ];

            return [
                'note_key' => 'saved-note',
                'mailbox' => $folder,
                'uid' => '2',
                'uidvalidity' => '22',
                'logical_uuid' => $append['logical_uuid'],
                'message_id' => '<saved@example.invalid>',
                'updated_at' => $append['updated_at'],
                'created_at' => $append['created_at'] ?? 'Sat, 12 Sep 2026 13:05:00 +0000',
                'title' => $append['subject'],
                'preview' => 'Body',
                'body_text' => 'Body',
                'body_html' => $append['html'],
                'fingerprint' => 'fp',
                'read_only' => false,
                'read_only_reason' => '',
                'cleanup_pending' => false,
                'cleanup_pending_target_uid' => '',
                'plugin_managed' => true,
                'legacy_apple' => true,
                'imported' => false,
            ];
        }

        return null;
    }

    public function checkCurrentRevision($folder, array $state)
    {
        return $this->conflict_state;
    }

    public function appendRevision($folder, array $message, array $note_data)
    {
        $this->last_append = $message;

        return [
            'success' => true,
            'revision' => [
                'note_key' => 'saved-note',
                'uid' => '2',
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
        $this->retire_called_with = compact('folder', 'state', 'new_revision');

        return ['cleanup_pending' => false];
    }

    public function deleteRevision($folder, array $state)
    {
        return $this->delete_result;
    }

    public function retryCleanup($folder, array $state)
    {
        return $this->retry_result;
    }
}

class ImapNotesRoundTripServiceTestStorage implements ImapNotesStorageInterface
{
    public $last_append;
    private $content;
    private $message_factory;
    private $persisted_message;
    private $persisted_note;
    private $persisted_storage_text = '';

    public function __construct(array $seed = [])
    {
        $this->content = new ImapNotesContent();
        $this->message_factory = new ImapNotesMessage();
        if (!empty($seed)) {
            $title = $seed['title'] ?? 'Próba';
            $storage_text = $seed['storage_text'] ?? $title;
            $html = $this->content->textToSafeHtml($storage_text);
            $message = $this->message_factory->createRevision(
                '11111111-1111-4111-8111-111111111111',
                $title,
                $html,
                'Tester <tester@example.test>',
                new DateTimeImmutable('2026-09-12T13:05:00Z'),
                new DateTimeImmutable('2026-09-12T13:05:00Z')
            );
            if (empty($seed['plugin_managed'])) {
                $message['raw'] = preg_replace("/^X-Roundcube-Note-Version:.*\r\n/m", '', $message['raw']);
            }
            if (empty($seed['legacy_apple'])) {
                $message['raw'] = preg_replace("/^X-Uniform-Type-Identifier:.*\r\n/m", '', $message['raw']);
            }
            $this->persisted_message = $message;
            $this->persisted_note = $this->buildPersistedNote($message);
        }
    }

    public function ensureFolder()
    {
        return 'Notes';
    }

    public function listRevisions($folder)
    {
        return $this->persisted_note ? [$this->persisted_note] : [];
    }

    public function loadRevision($folder, $note_key)
    {
        if ($note_key !== 'saved-note' || !$this->persisted_note) {
            return null;
        }

        $loaded = $this->buildPersistedNote($this->last_append ?: $this->persisted_message);
        $this->persisted_note = $loaded;

        return $loaded;
    }

    public function checkCurrentRevision($folder, array $state)
    {
        if (!$this->persisted_note) {
            return ['status' => 'missing'];
        }

        return ['status' => 'ok', 'current' => $this->persisted_note];
    }

    public function appendRevision($folder, array $message, array $note_data)
    {
        $this->last_append = $message + [
            'plugin_managed' => true,
            'legacy_apple' => true,
        ];
        $this->persisted_message = $this->last_append;
        $this->persisted_note = $this->buildPersistedNote($this->last_append);

        return [
            'success' => true,
            'revision' => [
                'note_key' => 'saved-note',
                'uid' => '2',
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
        return ['cleanup_pending' => false];
    }

    public function deleteRevision($folder, array $state)
    {
        return ['cleanup_pending' => false];
    }

    public function retryCleanup($folder, array $state)
    {
        return ['cleanup_pending' => false];
    }

    private function buildPersistedNote(array $message)
    {
        $raw = (string) ($message['raw'] ?? '');
        $parsed = $raw !== '' ? $this->message_factory->parseRawMessage($raw) : ['headers' => [], 'body' => ''];
        $headers = $parsed['headers'];
        $title = $headers ? mb_decode_mimeheader((string) ($headers['subject'] ?? '')) : ($message['subject'] ?? $message['title'] ?? 'Saved');
        $encoded_html = $headers ? (string) ($parsed['body'] ?? '') : '';
        $html = $headers && strtolower((string) ($headers['content-transfer-encoding'] ?? '')) === 'quoted-printable'
            ? quoted_printable_decode($encoded_html)
            : ($headers ? $encoded_html : ($message['html'] ?? '<html><body><p>Body</p></body></html>'));
        $body_text = $this->content->htmlToText($html);
        $plugin_managed = (string) ($headers['x-roundcube-note-version'] ?? '') !== '';
        $legacy_apple = strtolower((string) ($headers['x-uniform-type-identifier'] ?? '')) === 'com.apple.mail-note';
        $eligible_for_title_strip = $plugin_managed || $legacy_apple;
        $collapse_repeated_prefixes = $plugin_managed || $legacy_apple;
        $body_text = $this->content->normalizeImportedEditableBody(
            $title,
            $body_text,
            $eligible_for_title_strip,
            $collapse_repeated_prefixes
        );
        $this->persisted_storage_text = $this->content->htmlToText($html);

        return [
            'note_key' => 'saved-note',
            'mailbox' => 'Notes',
            'uid' => '2',
            'uidvalidity' => '22',
            'logical_uuid' => trim((string) ($headers['x-universally-unique-identifier'] ?? ($message['logical_uuid'] ?? '11111111-1111-4111-8111-111111111111'))),
            'message_id' => (string) ($headers['message-id'] ?? ($message['message_id'] ?? '<saved@example.invalid>')),
            'updated_at' => (string) ($headers['x-roundcube-note-updated'] ?? ($message['updated_at'] ?? '2026-09-12T13:05:00Z')),
            'created_at' => (string) ($headers['x-mail-created-date'] ?? ($message['created_at'] ?? 'Sat, 12 Sep 2026 13:05:00 +0000')),
            'title' => $title,
            'preview' => $this->content->previewText($body_text),
            'body_text' => $body_text,
            'body_html' => $this->content->sanitizeHtml($html),
            'fingerprint' => $this->content->fingerprint($title, $body_text),
            'read_only' => false,
            'read_only_reason' => '',
            'cleanup_pending' => false,
            'cleanup_pending_target_uid' => '',
            'plugin_managed' => $plugin_managed,
            'legacy_apple' => $legacy_apple,
            'imported' => false,
        ];
    }

    public function persistedStorageText()
    {
        return $this->persisted_storage_text;
    }
}

class ImapNotesServiceTestIdentityResolver implements ImapNotesIdentityResolverInterface
{
    private $from;
    private $throw;

    public function __construct($from, $throw = false)
    {
        $this->from = $from;
        $this->throw = $throw;
    }

    public function resolveFromHeader()
    {
        if ($this->throw) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        return $this->from;
    }
}
