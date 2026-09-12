<?php

class ImapNotesRevisionResolver
{
    public function selectDisplayNotes(array $revisions)
    {
        $grouped = [];

        foreach ($revisions as $revision) {
            $key = !empty($revision['logical_uuid'])
                ? 'uuid:' . strtolower($revision['logical_uuid'])
                : 'uid:' . $revision['uid'];
            $grouped[$key][] = $revision;
        }

        $active = [];
        $hidden = [];

        foreach ($grouped as $items) {
            usort($items, [$this, 'compareRevisions']);
            $items[0]['cleanup_pending'] = !empty($items[0]['cleanup_pending']) || count($items) > 1;
            $active[] = $items[0];

            for ($i = 1; $i < count($items); $i++) {
                $hidden[] = $items[$i];
            }
        }

        usort($active, [$this, 'compareRevisions']);

        return [
            'active' => $active,
            'hidden' => $hidden,
        ];
    }

    public function compareRevisions(array $a, array $b)
    {
        $time_a = $this->revisionTimestamp($a);
        $time_b = $this->revisionTimestamp($b);

        if ($time_a === $time_b) {
            return (int) $b['uid'] <=> (int) $a['uid'];
        }

        return $time_b <=> $time_a;
    }

    private function revisionTimestamp(array $revision)
    {
        foreach (['updated_at', 'internal_date'] as $field) {
            if (!empty($revision[$field])) {
                $time = strtotime($revision[$field]);
                if ($time !== false) {
                    return $time;
                }
            }
        }

        return 0;
    }
}
