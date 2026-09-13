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

        public function __construct($uid, $folder, $safe = false)
        {
            $data = self::$messages[$folder][(string) $uid] ?? null;
            if (!$data) {
                $this->headers = null;
                return;
            }

            $this->headers = new ImapNotesAppleFixturesHeaders($data['headers'] ?? []);
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

class ImapNotesAppleFixturesTest extends TestCase
{
    protected function setUp(): void
    {
        rcube_message::$messages = [];
    }

    public function testSimpleAppleFixtureStripsDuplicatedTitleFromEditableBody()
    {
        $storage = $this->newStorageWithFixture('legacy-mac-simple-7bit.eml', '1');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '1'));

        $this->assertSame('Legacy Title', $note['title']);
        $this->assertSame('Legacy body line', $note['body_text']);
    }

    public function testIosQuotedPrintableUtf8FixtureParsesUnicodeAndCreatedDate()
    {
        $storage = $this->newStorageWithFixture('legacy-ios-utf8-qp.eml', '2');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '2'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame('Ez egy próba jegyzet áéíóöőúüű', $note['body_text']);
        $this->assertSame('Sat, 12 Sep 2026 12:00:00 +0000', $note['created_at']);
    }

    public function testBase64Utf8FixtureParsesBody()
    {
        $storage = $this->newStorageWithFixture('legacy-utf8-base64.eml', '3');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '3'));

        $this->assertSame('Próba', $note['title']);
        $this->assertSame('áéíóöőúüű', $note['body_text']);
    }

    public function testMultipartFixtureIsReadableButReadOnly()
    {
        $storage = $this->newStorageWithFixture('multipart-related-with-attachment.eml', '4');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '4'));

        $this->assertSame('Multipart Title', $note['title']);
        $this->assertSame('Readable body', $note['body_text']);
        $this->assertTrue($note['read_only']);
        $this->assertStringContainsString('attachments or extra MIME parts', $note['read_only_reason']);
    }

    public function testFixtureWithToHeaderIsImportable()
    {
        $storage = $this->newStorageWithFixture('apple-with-to-header.eml', '5');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '5'));

        $this->assertSame('To Header Note', $note['title']);
        $this->assertSame('Imported despite To.', $note['body_text']);
    }

    public function testSubjectBodyMismatchFixtureKeepsLeadingBodyText()
    {
        $storage = $this->newStorageWithFixture('subject-body-mismatch.eml', '6');
        $note = $storage->loadRevision('Notes', ImapNotesRoundcubeStorage::encodeNoteKey('Notes', '6'));

        $this->assertSame('Subject title', $note['title']);
        $this->assertSame("Different first body line\n\nSecond line", $note['body_text']);
    }

    private function newStorageWithFixture($fixture, $uid)
    {
        if (basename($fixture) !== $fixture) {
            throw new InvalidArgumentException('Fixture filename must not include path segments.');
        }

        $fixtures_dir = realpath(__DIR__ . '/fixtures/apple');
        $fixture_path = realpath($fixtures_dir . DIRECTORY_SEPARATOR . $fixture);
        if ($fixtures_dir === false || $fixture_path === false || strpos($fixture_path, $fixtures_dir . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Fixture path is invalid: ' . $fixture);
        }

        $parsed = ImapNotesAppleFixturesParser::parseFile($fixture_path);
        rcube_message::$messages['Notes'][(string) $uid] = $parsed;

        return new ImapNotesRoundcubeStorage(
            new ImapNotesAppleFixturesRcmail(new ImapNotesAppleFixturesStorage()),
            new ImapNotesContent()
        );
    }
}

class ImapNotesAppleFixturesParser
{
    public static function parseFile($path)
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read fixture file: ' . $path);
        }

        [$headers, $body] = self::splitHeadersAndBody($raw);
        $content_type = (string) ($headers['content-type'] ?? 'text/plain');

        if (stripos($content_type, 'multipart/') === 0) {
            return self::parseMultipart($headers, $body, $content_type);
        }

        $decoded = self::decodeBody($body, $headers['content-transfer-encoding'] ?? '');
        $decoded = self::decodeCharset($decoded, $content_type);

        return [
            'headers' => $headers,
            'mimetype' => self::mimeTypeOnly(strtolower($content_type)),
            'body' => self::decodeHeaderValue($decoded),
            'attachments' => [],
        ];
    }

    private static function parseMultipart(array $headers, $body, $content_type)
    {
        $boundary = self::extractBoundary($content_type);
        $parts = $boundary ? explode('--' . $boundary, (string) $body) : [];
        $selected_type = '';
        $selected_body = '';
        $attachments = [];

        foreach ($parts as $part_raw) {
            $part_raw = trim((string) $part_raw);
            if ($part_raw === '' || $part_raw === '--') {
                continue;
            }
            if (substr($part_raw, -2) === '--') {
                $part_raw = substr($part_raw, 0, -2);
            }

            [$part_headers, $part_body] = self::splitHeadersAndBody($part_raw);
            $part_type = self::mimeTypeOnly(strtolower((string) ($part_headers['content-type'] ?? 'text/plain')));
            $decoded_body = self::decodeBody($part_body, $part_headers['content-transfer-encoding'] ?? '');
            $decoded_body = self::decodeCharset($decoded_body, (string) ($part_headers['content-type'] ?? ''));

            if ($selected_type === '' && in_array($part_type, ['text/html', 'text/plain'], true)) {
                $selected_type = $part_type;
                $selected_body = $decoded_body;
                continue;
            }

            if (strpos($part_type, 'text/') !== 0) {
                $attachments[] = ['mimetype' => $part_type];
            }
        }

        return [
            'headers' => $headers,
            'mimetype' => $selected_type,
            'body' => self::decodeHeaderValue($selected_body),
            'attachments' => $attachments,
        ];
    }

    private static function splitHeadersAndBody($raw)
    {
        $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", (string) $raw, 2);
        $headers = [];
        $current = null;
        foreach (self::splitLines($parts[0] ?? '') as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $current) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $current = strtolower(trim($name));
            $headers[$current] = trim($value);
        }

        return [$headers, $parts[1] ?? ''];
    }

    private static function splitLines($value)
    {
        return preg_split("/\r\n|\n|\r/", (string) $value);
    }

    private static function extractBoundary($content_type)
    {
        if (!preg_match('/boundary="?([^";]+)"?/i', $content_type, $match)) {
            return null;
        }

        return $match[1];
    }

    private static function mimeTypeOnly($content_type)
    {
        $parts = explode(';', (string) $content_type, 2);

        return trim(strtolower($parts[0] ?? ''));
    }

    private static function decodeBody($body, $transfer_encoding)
    {
        $encoding = strtolower(trim((string) $transfer_encoding));
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', (string) $body), true);
            return $decoded !== false ? $decoded : $body;
        }

        return $body;
    }

    private static function decodeCharset($value, $content_type)
    {
        if (!preg_match('/charset="?([^";]+)"?/i', (string) $content_type, $match)) {
            return (string) $value;
        }

        $charset = trim($match[1]);
        if ($charset === '' || strtoupper($charset) === 'UTF-8') {
            return (string) $value;
        }

        $converted = mb_convert_encoding((string) $value, 'UTF-8', $charset);

        return $converted !== false ? $converted : (string) $value;
    }

    public static function decodeHeaderValue($value)
    {
        if (preg_match('/=\?.+\?=/i', (string) $value) && function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader((string) $value);
        }

        return (string) $value;
    }
}

class ImapNotesAppleFixturesHeaders
{
    private $headers;
    public $internaldate;

    public function __construct(array $headers)
    {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            if ($name === 'date' || $name === 'internaldate') {
                $this->internaldate = $value;
            }
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function get($name)
    {
        return ImapNotesAppleFixturesParser::decodeHeaderValue((string) ($this->headers[strtolower($name)] ?? ''));
    }
}

class ImapNotesAppleFixturesStorageResult
{
    private $uids;

    public function __construct(array $uids)
    {
        $this->uids = array_values($uids);
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

class ImapNotesAppleFixturesStorage
{
    public function folder_data($folder)
    {
        return ['UIDVALIDITY' => '22'];
    }

    public function search_once($folder, $criteria)
    {
        if ($criteria === 'ALL UNDELETED') {
            return new ImapNotesAppleFixturesStorageResult(array_keys(rcube_message::$messages[$folder] ?? []));
        }
        if (strpos($criteria, 'UID ') === 0) {
            $uid = trim(substr($criteria, 4));
            $exists = isset(rcube_message::$messages[$folder][$uid]) ? [$uid] : [];
            return new ImapNotesAppleFixturesStorageResult($exists);
        }

        return new ImapNotesAppleFixturesStorageResult([]);
    }

    public function folder_exists($folder)
    {
        return true;
    }

    public function create_folder($folder, $subscribe)
    {
        return true;
    }
}

class ImapNotesAppleFixturesConfig
{
    public function get($key, $default = null)
    {
        if ($key === 'imap_notes_folder') {
            return 'Notes';
        }

        return $default;
    }
}

class ImapNotesAppleFixturesRcmail
{
    private $storage;
    public $config;

    public function __construct($storage)
    {
        $this->storage = $storage;
        $this->config = new ImapNotesAppleFixturesConfig();
    }

    public function get_storage()
    {
        return $this->storage;
    }
}
