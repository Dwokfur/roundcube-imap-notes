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

    public function testConflictCopyCreatesNewLogicalUuid()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'conflict', 'current' => ['note_key' => 'remote', 'title' => 'Remote']]);
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver());

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
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver());

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
        $storage->delete_result = ['cleanup_pending' => true];
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver());

        $result = $service->delete([
            'note_key' => 'saved-note',
            'uid' => '2',
        ]);

        $this->assertSame('delete_cleanup_pending', $result['status']);
    }

    public function testRetryCleanupReturnsCleanedStatusAfterSuccess()
    {
        $storage = new ImapNotesServiceTestStorage(['status' => 'ok']);
        $storage->retry_result = ['cleanup_pending' => false];
        $service = new ImapNotesService($storage, new ImapNotesContent(), new ImapNotesMessage(), new ImapNotesRevisionResolver(), new ImapNotesConflictResolver());

        $result = $service->retryCleanup([
            'note_key' => 'saved-note',
            'uid' => '2',
            'cleanup_pending_target_uid' => '1',
        ]);

        $this->assertSame('cleaned', $result['status']);
    }

    private function buildService(array $conflict_state)
    {
        return new ImapNotesService(
            new ImapNotesServiceTestStorage($conflict_state),
            new ImapNotesContent(),
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver()
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
                'modseq' => '',
                'logical_uuid' => $append['logical_uuid'],
                'updated_at' => $append['updated_at'],
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
