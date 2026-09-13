<?php

use PHPUnit\Framework\TestCase;

class ImapNotesMessageTest extends TestCase
{
    public function testGeneratedRevisionContainsExpectedHeaders()
    {
        $factory = new ImapNotesMessage();
        $revision = $factory->createRevision(
            '11111111-1111-4111-8111-111111111111',
            'Hello',
            '<html><body><p>Body</p></body></html>',
            '=?UTF-8?Q?J=C3=B3zsef_Kov=C3=A1cs?= <jozsef@example.test>',
            new DateTimeImmutable('2026-09-12T12:00:00Z'),
            new DateTimeImmutable('2026-09-12T13:00:00Z')
        );
        $parsed = $factory->parseRawMessage($revision['raw']);

        $this->assertSame('=?UTF-8?Q?J=C3=B3zsef_Kov=C3=A1cs?= <jozsef@example.test>', $parsed['headers']['from']);
        $this->assertSame('com.apple.mail-note', $parsed['headers']['x-uniform-type-identifier']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $parsed['headers']['x-universally-unique-identifier']);
        $this->assertSame('Sat, 12 Sep 2026 12:00:00 +0000', $parsed['headers']['x-mail-created-date']);
        $this->assertSame('1', $parsed['headers']['x-roundcube-note-version']);
        $this->assertSame('2026-09-12T13:00:00Z', $parsed['headers']['x-roundcube-note-updated']);
        $this->assertSame('text/html; charset=UTF-8', $parsed['headers']['content-type']);
        $this->assertSame('quoted-printable', $parsed['headers']['content-transfer-encoding']);
        $this->assertStringContainsString('@roundcube-imap-notes.invalid>', $parsed['headers']['message-id']);
        $this->assertArrayNotHasKey('to', $parsed['headers']);
    }

    public function testEachPhysicalRevisionGetsFreshMessageId()
    {
        $factory = new ImapNotesMessage();
        $first = $factory->createRevision('11111111-1111-4111-8111-111111111111', 'Hello', '<html><body></body></html>', 'jozsef@example.test');
        $second = $factory->createRevision('11111111-1111-4111-8111-111111111111', 'Hello', '<html><body></body></html>', 'jozsef@example.test');

        $this->assertNotSame($first['message_id'], $second['message_id']);
        $this->assertSame($first['logical_uuid'], $second['logical_uuid']);
    }

    public function testGeneratedQuotedPrintableBodyRoundTripsUtf8()
    {
        $factory = new ImapNotesMessage();
        $html = '<html><body><p>Ez egy próba jegyzet áéíóöőúüű Ελληνικά 日本語 😀</p></body></html>';
        $revision = $factory->createRevision(
            '11111111-1111-4111-8111-111111111111',
            'Próba',
            $html,
            '=?UTF-8?Q?J=C3=B3zsef_Kov=C3=A1cs?= <jozsef@example.test>',
            new DateTimeImmutable('2026-09-12T12:00:00Z'),
            new DateTimeImmutable('2026-09-12T13:00:00Z')
        );
        $parsed = $factory->parseRawMessage($revision['raw']);

        $this->assertStringContainsString('=C3=B3', $parsed['body']);
        $this->assertSame($html, quoted_printable_decode($parsed['body']));
    }

    public function testParseRawMessageLeavesQuotedPrintableSoftWrapsForExplicitDecoding()
    {
        $factory = new ImapNotesMessage();
        $parsed = $factory->parseRawMessage(
            "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "\r\n"
            . "<p>Ez egy pr=C3=B3ba=\r\n jegyzet</p>"
        );

        $this->assertSame("<p>Ez egy pr=C3=B3ba=\r\n jegyzet</p>", $parsed['body']);
        $this->assertSame('<p>Ez egy próba jegyzet</p>', quoted_printable_decode($parsed['body']));
    }

    public function testCreatedDatePersistsAcrossRevisionsWhileDateMessageIdAndUpdatedChange()
    {
        $factory = new ImapNotesMessage();
        $created = new DateTimeImmutable('2026-09-12T10:30:00Z');
        $first = $factory->createRevision(
            '11111111-1111-4111-8111-111111111111',
            'Hello',
            '<html><body></body></html>',
            'jozsef@example.test',
            $created,
            new DateTimeImmutable('2026-09-12T13:00:00Z')
        );
        $second = $factory->createRevision(
            '11111111-1111-4111-8111-111111111111',
            'Hello',
            '<html><body></body></html>',
            'jozsef@example.test',
            $created,
            new DateTimeImmutable('2026-09-12T13:05:00Z')
        );

        $parsed_first = $factory->parseRawMessage($first['raw']);
        $parsed_second = $factory->parseRawMessage($second['raw']);

        $this->assertSame($parsed_first['headers']['x-mail-created-date'], $parsed_second['headers']['x-mail-created-date']);
        $this->assertNotSame($parsed_first['headers']['date'], $parsed_second['headers']['date']);
        $this->assertNotSame($parsed_first['headers']['message-id'], $parsed_second['headers']['message-id']);
        $this->assertNotSame($parsed_first['headers']['x-roundcube-note-updated'], $parsed_second['headers']['x-roundcube-note-updated']);
    }
}
