<?php

use PHPUnit\Framework\TestCase;

class ImapNotesRevisionResolverTest extends TestCase
{
    public function testNewestRevisionWinsForDuplicateLogicalUuid()
    {
        $resolver = new ImapNotesRevisionResolver();
        $result = $resolver->selectDisplayNotes([
            ['uid' => '10', 'logical_uuid' => 'abc', 'updated_at' => '2026-09-12T13:00:00Z', 'internal_date' => 'Sat, 12 Sep 2026 13:00:00 +0000'],
            ['uid' => '11', 'logical_uuid' => 'abc', 'updated_at' => '2026-09-12T13:05:00Z', 'internal_date' => 'Sat, 12 Sep 2026 13:05:00 +0000'],
            ['uid' => '12', 'logical_uuid' => '', 'updated_at' => '', 'internal_date' => 'Sat, 12 Sep 2026 12:00:00 +0000'],
        ]);

        $this->assertCount(2, $result['active']);
        $this->assertSame('11', $result['active'][0]['uid']);
        $this->assertCount(1, $result['hidden']);
    }
}
