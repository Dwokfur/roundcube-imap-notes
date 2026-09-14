<?php

class ImapNotesContent
{
    private $allowed_tags = [
        'html', 'body', 'p', 'br', 'div', 'span', 'strong', 'em', 'b', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a',
    ];

    private $drop_tags = [
        'script', 'style', 'form', 'input', 'button', 'textarea', 'select', 'option',
        'iframe', 'frame', 'frameset', 'embed', 'object', 'link', 'meta', 'base',
    ];

    public function normalizePlainText($text)
    {
        $text = $this->ensureUtf8((string) $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace("/\x{00}/u", '', $text);
    }

    public function deriveTitle($title, $body, $fallback = 'Untitled note')
    {
        $title = trim(preg_replace('/\s+/u', ' ', $this->normalizePlainText($title)));
        if ($title !== '') {
            return $title;
        }

        $body = $this->normalizePlainText($body);
        foreach (preg_split('/\n/', $body) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                return $line;
            }
        }

        return $fallback;
    }

    public function textToSafeHtml($text)
    {
        $text = $this->normalizePlainText($text);
        $trimmed = trim($text, "\n");

        if ($trimmed === '') {
            return "<html><body></body></html>";
        }

        $paragraphs = preg_split('/\n{2,}/', $trimmed);
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $lines = array_map([$this, 'escapeHtml'], explode("\n", $paragraph));
            $html[] = '<p>' . implode("<br />\n", $lines) . '</p>';
        }

        return "<html><body>\n" . implode("\n", $html) . "\n</body></html>";
    }

    public function composeStorageBodyText($title, $body)
    {
        $title = trim(preg_replace('/\s+/u', ' ', $this->normalizePlainText($title)));
        $body = $this->normalizePlainText($body);

        if ($title === '') {
            return $body;
        }
        if (trim($body) === '') {
            return $title;
        }

        return $title . "\n\n" . $body;
    }

    /**
     * @param bool $eligible_for_title_strip Strip a single leading title line when the body is known to use title-prefixed note storage.
     * @param bool $collapse_repeated_prefixes Collapse duplicated title-plus-blank-line prefixes for compatible imports before editing.
     */
    public function normalizeImportedEditableBody($subject, $body_text, $eligible_for_title_strip, $collapse_repeated_prefixes = false)
    {
        $body_text = $this->normalizePlainText($body_text);
        $normalized_subject = $this->normalizeVisibleLine($subject);
        if ($normalized_subject === '') {
            return $body_text;
        }

        $lines = preg_split('/\n/', $body_text);
        $first_index = null;
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $first_index = $index;
                break;
            }
        }

        if ($first_index === null) {
            return $body_text;
        }

        if ($this->normalizeVisibleLine($lines[$first_index]) !== $normalized_subject) {
            return $body_text;
        }

        if ($collapse_repeated_prefixes) {
            $scan_index = $first_index;
            $prefix_copies = 0;
            while (
                isset($lines[$scan_index], $lines[$scan_index + 1])
                && $this->normalizeVisibleLine($lines[$scan_index]) === $normalized_subject
                && trim($lines[$scan_index + 1]) === ''
            ) {
                $prefix_copies++;
                $scan_index += 2;
            }

            if ($prefix_copies >= 2) {
                array_splice($lines, $first_index, $scan_index - $first_index);

                return implode("\n", $lines);
            }
        }

        if (!$eligible_for_title_strip) {
            return $body_text;
        }

        array_splice($lines, $first_index, 1);
        if (isset($lines[$first_index]) && trim($lines[$first_index]) === '') {
            array_splice($lines, $first_index, 1);
        }

        return implode("\n", $lines);
    }

    public function sanitizeHtml($html)
    {
        $html = $this->ensureUtf8((string) $html);
        $previous = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML($this->htmlForParsing($html), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);

        $body = $this->extractBody($dom);
        if ($body) {
            $this->sanitizeChildren($body);
        }

        $inner = $body ? $this->serializeChildren($dom, $body) : '';

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return '<html><body>' . $inner . '</body></html>';
    }

    public function htmlToText($html)
    {
        $html = $this->sanitizeHtml($html);
        $body = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $body = preg_replace('/<\/(p|div|h[1-6]|li|blockquote|pre)>/i', "$0\n", $body);
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $body = $this->normalizePlainText($body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        return trim($body);
    }

    public function previewText($text, $length = 160)
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->normalizePlainText($text)));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }

    public function fingerprint($title, $body)
    {
        return hash('sha256', $this->deriveTitle($title, $body) . "\n" . $this->normalizePlainText($body));
    }

    private function sanitizeChildren(DOMNode $node)
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }

            if (!($child instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, $this->drop_tags, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, $this->allowed_tags, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, $tag)
    {
        $allowed = [];
        if ($tag === 'a') {
            $allowed = ['href', 'title', 'rel'];
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (strpos($name, 'on') === 0 || in_array($name, ['style', 'src', 'srcset', 'action', 'formaction', 'target'], true)) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            if ($name === 'href' && !$this->isSafeHref($value)) {
                $element->removeAttributeNode($attribute);
                continue;
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private function isSafeHref($href)
    {
        if ($href === '' || $href[0] === '#') {
            return true;
        }

        if (strpos($href, '//') === 0) {
            return false;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null) {
            return true;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    private function escapeHtml($text)
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function htmlForParsing($html)
    {
        $prefix = '<?xml encoding="UTF-8">';

        if (preg_match('/<\s*(?:!doctype|html|head|body)\b/i', $html)) {
            return $prefix . $html;
        }

        return $prefix . '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
    }

    private function extractBody(DOMDocument $dom)
    {
        return $dom->getElementsByTagName('body')->item(0);
    }

    private function serializeChildren(DOMDocument $dom, DOMNode $node)
    {
        $html = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }

    private function ensureUtf8($value)
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding) {
            return mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function normalizeVisibleLine($value)
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $this->normalizePlainText((string) $value)));
    }
}
