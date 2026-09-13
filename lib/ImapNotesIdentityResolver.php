<?php

class ImapNotesIdentityResolver implements ImapNotesIdentityResolverInterface
{
    private $rcmail;

    public function __construct($rcmail)
    {
        $this->rcmail = $rcmail;
    }

    public function resolveFromHeader()
    {
        $identity = $this->findDefaultIdentity();
        $email = trim((string) ($identity['email'] ?? ''));
        $name = (string) ($identity['name'] ?? $identity['display_name'] ?? '');

        if ($email === '' || !$this->isSafeIdentityValue($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        if (!$this->isSafeIdentityValue($name)) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        $formatted = $this->formatIdentityAddress($email, $name);
        $formatted = trim($formatted);
        if ($formatted === '' || !$this->isSafeIdentityValue($formatted)) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        return $formatted;
    }

    private function findDefaultIdentity()
    {
        $user = $this->rcmail->user ?? null;
        if (!$user || !method_exists($user, 'list_identities')) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        $identities = $user->list_identities();
        if (!is_array($identities)) {
            $identities = [];
        }

        $selected = null;
        foreach ($identities as $identity) {
            if (!is_array($identity)) {
                continue;
            }

            if (!empty($identity['standard'])) {
                $selected = $identity;
                break;
            }

            if ($selected === null) {
                $selected = $identity;
            }
        }

        if ($selected === null) {
            throw new RuntimeException('A valid default identity is required to save notes.');
        }

        return $selected;
    }

    private function formatIdentityAddress($email, $name)
    {
        if (class_exists('rcube_mime') && method_exists('rcube_mime', 'format_email_recipient')) {
            $formatted = (string) rcube_mime::format_email_recipient($email, $name);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        if (trim($name) === '') {
            return $email;
        }

        $encoded_name = mb_encode_mimeheader($name, 'UTF-8', 'Q', '');
        $encoded_name = str_replace(["\r", "\n"], '', $encoded_name);

        return $encoded_name . ' <' . $email . '>';
    }

    private function isSafeIdentityValue($value)
    {
        return preg_match('/[\x00-\x1F\x7F]/', (string) $value) !== 1;
    }
}
