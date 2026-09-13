<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('rcube_mime')) {
    class rcube_mime
    {
        public static function format_email_recipient($email, $name = '')
        {
            if (trim((string) $name) === '') {
                return $email;
            }

            return mb_encode_mimeheader($name, 'UTF-8', 'Q', "\r\n") . ' <' . $email . '>';
        }
    }
}

class ImapNotesIdentityResolverTest extends TestCase
{
    public function testResolveFromSupportsUnicodeDisplayName()
    {
        $resolver = new ImapNotesIdentityResolver(new ImapNotesIdentityResolverTestRcmail([
            ['standard' => true, 'name' => 'József Kovács', 'email' => 'jozsef@example.test'],
        ]));

        $from = $resolver->resolveFromHeader();

        $this->assertStringContainsString('<jozsef@example.test>', $from);
        $this->assertStringContainsString('=?UTF-8?', $from);
    }

    public function testMissingIdentityFailsSafely()
    {
        $resolver = new ImapNotesIdentityResolver(new ImapNotesIdentityResolverTestRcmail([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid default identity');
        $resolver->resolveFromHeader();
    }

    public function testInvalidIdentityWithControlCharactersFailsSafely()
    {
        $resolver = new ImapNotesIdentityResolver(new ImapNotesIdentityResolverTestRcmail([
            ['standard' => true, 'name' => "József\r\nKovács", 'email' => 'jozsef@example.test'],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid default identity');
        $resolver->resolveFromHeader();
    }
}

class ImapNotesIdentityResolverTestRcmail
{
    public $user;

    public function __construct(array $identities)
    {
        $this->user = new ImapNotesIdentityResolverTestUser($identities);
    }
}

class ImapNotesIdentityResolverTestUser
{
    private $identities;

    public function __construct(array $identities)
    {
        $this->identities = $identities;
    }

    public function list_identities()
    {
        return $this->identities;
    }
}
