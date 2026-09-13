<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('rcube_plugin')) {
    class rcube_plugin
    {
        public static $labels = [];
        public $included_stylesheets = [];
        public $included_scripts = [];

        public function gettext($label)
        {
            return self::$labels[$label] ?? $label;
        }

        public function add_texts($path, $merge)
        {
        }

        public function register_task($task)
        {
        }

        public function register_action($action, $callback)
        {
        }

        public function add_hook($name, $callback)
        {
        }

        public function add_button($button, $container)
        {
        }

        public function include_stylesheet($path)
        {
            $this->included_stylesheets[] = $path;
        }

        public function include_script($path)
        {
            $this->included_scripts[] = $path;
        }

        public function local_skin_path()
        {
            return 'skins/elastic';
        }
    }
}

if (!class_exists('rcube')) {
    class rcube
    {
        public static function Q($value)
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
    }
}

if (!class_exists('rcmail')) {
    class rcmail
    {
        public static $instance;

        public static function get_instance()
        {
            return self::$instance;
        }
    }
}

require_once __DIR__ . '/../imap_notes.php';

class ImapNotesPluginUiTest extends TestCase
{
    protected function setUp(): void
    {
        rcube_plugin::$labels = [
            'newnote' => 'New note',
            'title' => 'Title',
            'body' => 'Note',
            'conflicttitle' => 'Conflict detected',
            'conflictmessage' => 'Conflict message',
            'renderedpreview' => 'Rendered preview',
            'revisioncleanuppending' => 'Cleanup pending banner',
            'cleanuppendinglabel' => 'Cleanup pending.',
            'delete' => 'Delete',
            'deleteconfirm' => 'Delete this note?',
        ];
    }

    public function testStartupIncludesElasticScriptForDeleteConfirmation()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $rc->task = 'imap_notes';
        $this->setPrivate($plugin, 'rc', $rc);

        $plugin->startup([]);

        $this->assertContains('skins/elastic/imap_notes.js', $plugin->included_scripts);
        $this->assertContains('skins/elastic/imap_notes.css', $plugin->included_stylesheets);
    }

    public function testNotesListMarksCurrentNoteAndExposesCleanupStateWithoutListRole()
    {
        $plugin = $this->newPluginWithViewData([
            'folder' => 'Notes/Subfolder With A Very Long Name',
            'notes' => [
                [
                    'note_key' => 'note-1',
                    'title' => 'Selected note',
                    'preview' => 'Preview text',
                    'cleanup_pending' => true,
                ],
                [
                    'note_key' => 'note-2',
                    'title' => 'Other note',
                    'preview' => 'Other preview',
                    'cleanup_pending' => false,
                ],
            ],
            'selected' => ['note_key' => 'note-1'],
        ]);

        $html = $plugin->notes_list([]);

        $this->assertStringNotContainsString('role="list"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertRegExp('/aria-describedby="imap-note-status-note-1-0"/', $html);
        $this->assertStringContainsString('Cleanup pending.', $html);
        $this->assertStringContainsString('aria-label="Selected note"', $html);
        $this->assertStringNotContainsString('aria-label="Selected note Preview text"', $html);
    }

    public function testNotesEditorAssociatesLabelsAndAddsAlertStatusAndDeleteConfirmation()
    {
        $plugin = $this->newPluginWithViewData([
            'folder' => 'Notes',
            'notes' => [],
            'selected' => [
                'note_key' => 'note-1',
                'uid' => '4',
                'mailbox' => 'Notes',
                'title' => 'My title',
                'body_text' => 'Body text',
                'body_html' => '<html><body><p>Body text</p></body></html>',
                'cleanup_pending' => true,
                'cleanup_pending_target_uid' => '3',
                'read_only' => false,
            ],
            'conflict' => ['title' => 'Remote title'],
        ]);

        $html = $plugin->notes_editor([]);

        $this->assertRegExp('/<label for="imap-notes-title-note-1-4">Title<\\/label><input id="imap-notes-title-note-1-4"/', $html);
        $this->assertRegExp('/<label for="imap-notes-body-note-1-4">Note<\\/label><textarea id="imap-notes-body-note-1-4"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('data-confirm="Delete this note?"', $html);
    }

    public function testReadOnlyEditorKeepsLabeledFieldsAndRenderedPreview()
    {
        $plugin = $this->newPluginWithViewData([
            'folder' => 'Notes',
            'notes' => [],
            'selected' => [
                'note_key' => 'readonly',
                'uid' => '7',
                'mailbox' => 'Notes',
                'title' => 'Read only title',
                'body_text' => 'Read only body',
                'body_html' => '<html><body><pre>https://example.test/really/long/url</pre></body></html>',
                'cleanup_pending' => false,
                'read_only' => true,
                'read_only_reason' => 'Read only reason',
            ],
        ]);

        $html = $plugin->notes_editor([]);

        $this->assertStringContainsString('readonly="readonly"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('role="region"', $html);
        $this->assertRegExp('/<label for="imap-notes-title-readonly-7">Title<\\/label>/', $html);
        $this->assertRegExp('/<label for="imap-notes-body-readonly-7">Note<\\/label>/', $html);
    }

    private function newPluginWithViewData(array $view_data)
    {
        $plugin = new imap_notes();
        $this->setPrivate($plugin, 'rc', new ImapNotesPluginUiTestFakeRcmail());
        $this->setPrivate($plugin, 'content', new ImapNotesContent());
        $this->setPrivate($plugin, 'view_data', $view_data);

        return $plugin;
    }

    private function setPrivate($object, $property, $value)
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}

class ImapNotesPluginUiTestFakeRcmail
{
    public $task = '';
    public $output;

    public function __construct()
    {
        $this->output = (object) ['framed' => false];
    }

    public function url(array $params)
    {
        return '?' . http_build_query($params, '', '&amp;');
    }

    public function get_request_token()
    {
        return 'request-token';
    }
}
