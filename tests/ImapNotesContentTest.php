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
}
