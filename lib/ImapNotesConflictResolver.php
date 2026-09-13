<?php

class ImapNotesConflictResolver
{
    public function resolve(array $state, $decision = null)
    {
        $status = $state['status'] ?? 'ok';

        if ($status === 'ok') {
            return ['status' => 'proceed', 'copy' => false, 'overwrite' => false];
        }

        if ($status === 'missing') {
            return ['status' => 'proceed', 'copy' => false, 'overwrite' => false];
        }

        if ($decision === 'reload') {
            return ['status' => 'reload'];
        }

        if ($decision === 'overwrite') {
            return ['status' => 'proceed', 'copy' => false, 'overwrite' => true];
        }

        if ($decision === 'copy') {
            return ['status' => 'proceed', 'copy' => true, 'overwrite' => false];
        }

        return ['status' => 'conflict', 'default_decision' => 'copy'];
    }
}
