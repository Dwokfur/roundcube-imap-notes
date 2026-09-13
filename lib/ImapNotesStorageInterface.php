<?php

interface ImapNotesStorageInterface
{
    public function ensureFolder();

    public function listRevisions($folder);

    public function loadRevision($folder, $note_key);

    public function checkCurrentRevision($folder, array $state);

    public function appendRevision($folder, array $message, array $note_data);

    public function retireRevision($folder, array $state, array $new_revision);

    public function deleteRevision($folder, array $state);

    public function retryCleanup($folder, array $state);
}
