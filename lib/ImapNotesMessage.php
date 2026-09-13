<?php

class ImapNotesMessage
{
    public function createRevision($logical_uuid, $subject, $html, DateTimeImmutable $updated = null)
    {
        $updated = $updated ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $updated = $updated->setTimezone(new DateTimeZone('UTC'));
        $revision_uuid = self::uuidV4();
        $message_id = '<' . $revision_uuid . '@roundcube-imap-notes.invalid>';
        $date = $updated->format('D, d M Y H:i:s O');
        $updated_header = $updated->format('Y-m-d\TH:i:s\Z');

        $headers = [
            'Date: ' . $date,
            'Message-ID: ' . $message_id,
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n"),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Uniform-Type-Identifier: com.apple.mail-note',
            'X-Universally-Unique-Identifier: ' . $logical_uuid,
            'X-Roundcube-Note-Version: 1',
            'X-Roundcube-Note-Updated: ' . $updated_header,
        ];

        $raw = implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($html);

        return [
            'logical_uuid' => $logical_uuid,
            'message_id' => $message_id,
            'subject' => $subject,
            'updated_at' => $updated_header,
            'date' => $date,
            'html' => $html,
            'raw' => $raw,
        ];
    }

    /**
     * Test helper: returns parsed headers plus the raw transfer-encoded body bytes.
     * Tests that need decoded content should decode the body explicitly from headers.
     */
    public function parseRawMessage($raw)
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $header_lines = preg_split("/\r?\n/", $parts[0]);
        $headers = [];
        $current = null;

        foreach ($header_lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $current) {
                $headers[$current] .= trim($line);
                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $current = strtolower(trim($name));
            $headers[$current] = trim($value);
        }

        return [
            'headers' => $headers,
            'body' => $parts[1] ?? '',
        ];
    }

    public static function uuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
