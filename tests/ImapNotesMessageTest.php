<?php

use PHPUnit\Framework\TestCase;

class ImapNotesMessageTest extends TestCase
{
    public function testGeneratedRevisionContainsExpectedHeaders()
    {
        $factory = new ImapNotesMessage();
        $revision = $factory->createRevision('11111111-1111-4111-8111-111111111111', 'Hello', '<html><body><p>Body</p></body></html>', new DateTimeImmutable('2026-09-12T13:00:00Z'));
        $parsed = $factory->parseRawMessage($revision['raw']);

        $this->assertSame('com.apple.mail-note', $parsed['headers']['x-uniform-type-identifier']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $parsed['headers']['x-universally-unique-identifier']);
        $this->assertSame('1', $parsed['headers']['x-roundcube-note-version']);
        $this->assertSame('2026-09-12T13:00:00Z', $parsed['headers']['x-roundcube-note-updated']);
        $this->assertSame('text/html; charset=UTF-8', $parsed['headers']['content-type']);
        $this->assertStringContainsString('@roundcube-imap-notes.invalid>', $parsed['headers']['message-id']);
    }

    public function testEachPhysicalRevisionGetsFreshMessageId()
    {
        $factory = new ImapNotesMessage();
        $first = $factory->createRevision('11111111-1111-4111-8111-111111111111', 'Hello', '<html><body></body></html>');
        $second = $factory->createRevision('11111111-1111-4111-8111-111111111111', 'Hello', '<html><body></body></html>');

        $this->assertNotSame($first['message_id'], $second['message_id']);
        $this->assertSame($first['logical_uuid'], $second['logical_uuid']);
    }
}
