<?php

use PHPUnit\Framework\TestCase;

class ImapNotesContentTest extends TestCase
{
    public function testPlainTextCanonicalizesToDeterministicHtml()
    {
        $content = new ImapNotesContent();
        $html = $content->textToSafeHtml("Line 1\r\nLine 2\n\nLine 3");

        $this->assertSame("<html><body>\n<p>Line 1<br />\nLine 2</p>\n<p>Line 3</p>\n</body></html>", $html);
    }

    public function testSanitizerRemovesScriptsEventHandlersAndRemoteImages()
    {
        $content = new ImapNotesContent();
        $safe = $content->sanitizeHtml('<p onclick="alert(1)">Hi<script>alert(1)</script><img src="https://example.com/x.png" /><a href="javascript:alert(1)" target="_blank">x</a></p>');

        $this->assertStringNotContainsString('script', $safe);
        $this->assertStringNotContainsString('onclick', $safe);
        $this->assertStringNotContainsString('img', $safe);
        $this->assertStringNotContainsString('javascript:', $safe);
        $this->assertStringNotContainsString('target=', $safe);
        $this->assertStringContainsString('<p>Hi<a>x</a></p>', $safe);
    }

    public function testTitleFallsBackToFirstNonEmptyBodyLine()
    {
        $content = new ImapNotesContent();

        $this->assertSame('First body line', $content->deriveTitle('', "\n\nFirst body line\nSecond line", 'Untitled note'));
        $this->assertSame('Untitled note', $content->deriveTitle('', "\n\n", 'Untitled note'));
    }

    public function testSanitizerHandlesMalformedHtmlAndPreservesSafeLinks()
    {
        $content = new ImapNotesContent();
        $safe = $content->sanitizeHtml('<p><strong>Bold<a href="https://example.com" target="_blank">link');

        $this->assertStringContainsString('<strong>Bold<a href="https://example.com" rel="noopener noreferrer nofollow">link</a></strong>', $safe);
        $this->assertStringNotContainsString('target=', $safe);
    }

    public function testSanitizerRejectsSchemeRelativeLinks()
    {
        $content = new ImapNotesContent();
        $safe = $content->sanitizeHtml('<a href="//example.com/path">link</a>');

        $this->assertStringNotContainsString('//example.com/path', $safe);
        $this->assertStringContainsString('<a>link</a>', $safe);
    }

    public function testSanitizeHtmlPreservesHungarianUtf8RoundTrip()
    {
        $content = new ImapNotesContent();
        $html = '<p>Ez egy próba jegyzet áéíóöőúüű</p>';
        $safe = $content->sanitizeHtml($html);

        $this->assertStringContainsString('Ez egy próba jegyzet áéíóöőúüű', $safe);
        $this->assertSame('Ez egy próba jegyzet áéíóöőúüű', $content->htmlToText($safe));
    }

    public function testSanitizeHtmlPreservesUnicodeOutsideLatin1()
    {
        $content = new ImapNotesContent();
        $html = '<p>Ελληνικά — кириллица — 日本語 — 😀</p>';
        $safe = $content->sanitizeHtml($html);

        $this->assertStringContainsString('Ελληνικά — кириллица — 日本語 — 😀', $safe);
        $this->assertSame('Ελληνικά — кириллица — 日本語 — 😀', $content->htmlToText($safe));
    }

    public function testSanitizeHtmlCanonicalizesFullDocumentWithoutNestedMarkup()
    {
        $content = new ImapNotesContent();
        $safe = $content->sanitizeHtml('<html><head><title>Ignored</title><script>alert(1)</script></head><body><p>Visible <strong>text</strong></p></body></html>');

        $this->assertSame('<html><body><p>Visible <strong>text</strong></p></body></html>', $safe);
        $this->assertSame(1, substr_count($safe, '<html>'));
        $this->assertSame(1, substr_count($safe, '<body>'));
    }

    public function testNormalizePlainTextConvertsLikelyLegacyEncoding()
    {
        $content = new ImapNotesContent();
        $latin1 = mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8');
        $valid_utf8 = 'Próba 😀';

        $this->assertSame('café', $content->normalizePlainText($latin1));
        $this->assertSame($valid_utf8, $content->normalizePlainText($valid_utf8));
    }

    public function testComposeStorageBodyTextDuplicatesTitleForAppleCompatibility()
    {
        $content = new ImapNotesContent();

        $this->assertSame(
            "Próba\n\nEz egy próba jegyzet",
            $content->composeStorageBodyText('Próba', 'Ez egy próba jegyzet')
        );
    }

    public function testComposeStorageBodyTextKeepsTitleOnlyWithoutExtraBlankBodyLine()
    {
        $content = new ImapNotesContent();

        $this->assertSame('Próba', $content->composeStorageBodyText('Próba', ''));
    }

    public function testNormalizeImportedEditableBodyStripsDuplicatedTitleForCompatibleMessages()
    {
        $content = new ImapNotesContent();
        $body = "Próba\n\nEz egy próba jegyzet";

        $this->assertSame(
            'Ez egy próba jegyzet',
            $content->normalizeImportedEditableBody('Próba', $body, true)
        );
    }

    public function testNormalizeImportedEditableBodyRepairsConsecutivePluginManagedPrefixes()
    {
        $content = new ImapNotesContent();
        $body = "Próba\n\nPróba\n\nEz egy próba jegyzet";

        $this->assertSame(
            'Ez egy próba jegyzet',
            $content->normalizeImportedEditableBody('Próba', $body, true, true)
        );
    }

    public function testNormalizeImportedEditableBodyCanCollapseRepeatedPrefixesWithoutSingleStrip()
    {
        $content = new ImapNotesContent();
        $body = "Próba\n\nPróba\n\nEz egy próba jegyzet";

        $this->assertSame(
            'Ez egy próba jegyzet',
            $content->normalizeImportedEditableBody('Próba', $body, false, true)
        );
    }

    public function testNormalizeImportedEditableBodyKeepsCompatibleBodyWhenSubjectDoesNotMatch()
    {
        $content = new ImapNotesContent();
        $body = "Próba\n\nEz egy próba jegyzet";

        $this->assertSame(
            $body,
            $content->normalizeImportedEditableBody('', $body, true, true)
        );
    }

    public function testNormalizeImportedEditableBodyKeepsMismatchUntouched()
    {
        $content = new ImapNotesContent();
        $body = "Első sor\n\nTovábbi tartalom";

        $this->assertSame(
            $body,
            $content->normalizeImportedEditableBody('Más cím', $body, true)
        );
    }

    public function testNormalizeImportedEditableBodyMatchesSubjectWithUnicodeWhitespace()
    {
        $content = new ImapNotesContent();
        $subject = "Árvíztűrő\u{00A0}tükörfúrógép";
        $body = "Árvíztűrő tükörfúrógép\n\nTörzs";

        $this->assertSame(
            'Törzs',
            $content->normalizeImportedEditableBody($subject, $body, true, true)
        );
    }

    public function testRepeatedStorageLoadCyclesKeepEditorBodyStableAndSingleLeadingTitlePrefix()
    {
        $content = new ImapNotesContent();
        $title = 'Próba';
        $original_body = 'Ez egy próba jegyzet';
        $editable_body = $original_body;

        for ($i = 0; $i < 3; $i++) {
            $stored = $content->composeStorageBodyText($title, $editable_body);
            $this->assertSame("Próba\n\nEz egy próba jegyzet", $stored);
            $html = $content->textToSafeHtml($stored);
            $this->assertSame("<html><body>\n<p>Próba</p>\n<p>Ez egy próba jegyzet</p>\n</body></html>", $html);

            $editable_body = $content->normalizeImportedEditableBody($title, $content->htmlToText($html), true, true);
            $this->assertSame($original_body, $editable_body);
        }
    }
}
