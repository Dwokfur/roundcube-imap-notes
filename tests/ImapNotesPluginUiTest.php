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

if (!class_exists('rcube_utils')) {
    class rcube_utils
    {
        const INPUT_GPC = 0;

        public static function get_input_string($name, $source)
        {
            return isset($_POST[$name]) ? (string) $_POST[$name] : '';
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
            'confirmdelete' => 'Confirm delete',
            'canceldelete' => 'Cancel',
            'deleteconfirm' => 'Delete this note?',
        ];
        $_POST = [];
    }

    public function testStartupIncludesElasticStylesheetsForNotesTask()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $rc->task = 'imap_notes';
        $this->setPrivate($plugin, 'rc', $rc);

        $plugin->startup([]);

        $this->assertSame([], $plugin->included_scripts);
        $this->assertContains('skins/elastic/imap_notes.css', $plugin->included_stylesheets);
    }

    public function testStartupDoesNotIncludeElasticNotesStylesheetOutsideNotesTask()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $rc->task = 'mail';
        $this->setPrivate($plugin, 'rc', $rc);

        $plugin->startup([]);

        $this->assertNotContains('skins/elastic/imap_notes.css', $plugin->included_stylesheets);
        $this->assertSame([], $plugin->included_scripts);
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

        $this->assertStringContainsString('class="button create btn btn-secondary"', $html);
        $this->assertStringContainsString('?task=imap_notes&amp;action=index&amp;_new=1', $html);
        $this->assertStringNotContainsString('role="list"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertHtmlMatches('/aria-describedby="imap-note-status-note-1-0"/', $html);
        $this->assertStringContainsString('Cleanup pending.', $html);
        $this->assertStringContainsString('aria-label="Selected note"', $html);
        $this->assertStringNotContainsString('aria-label="Selected note Preview text"', $html);
    }

    public function testActionIndexPassesExplicitNewNoteModeToService()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $service = new ImapNotesPluginUiTestFakeService([
            'folder' => 'Notes',
            'notes' => [['note_key' => 'note-1', 'title' => 'Existing', 'preview' => 'Existing preview']],
            'selected' => ['note_key' => '', 'mailbox' => 'Notes', 'title' => '', 'body_text' => ''],
        ]);

        $this->setPrivate($plugin, 'rc', $rc);
        $this->setPrivate($plugin, 'content', new ImapNotesContent());
        $this->setPrivate($plugin, 'service', $service);

        $_POST = ['_new' => '1'];
        $plugin->action_index();

        $this->assertSame([['note_key' => '', 'new_note' => true]], $service->view_calls);
    }

    public function testActionSaveKeepsReloadedCleanBodyInsteadOfStaleSavedSelectionBody()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $service = new ImapNotesPluginUiTestFakeService([
            'folder' => 'Notes',
            'notes' => [['note_key' => 'saved-note', 'title' => 'Próba', 'preview' => 'Ez egy próba jegyzet']],
            'selected' => [
                'note_key' => 'saved-note',
                'mailbox' => 'Notes',
                'uid' => '2',
                'uidvalidity' => '22',
                'logical_uuid' => '11111111-1111-4111-8111-111111111111',
                'message_id' => '<saved@example.invalid>',
                'updated_at' => '2026-09-12T13:05:00Z',
                'created_at' => 'Sat, 12 Sep 2026 13:05:00 +0000',
                'title' => 'Próba',
                'preview' => 'Ez egy próba jegyzet',
                'body_text' => 'Ez egy próba jegyzet',
                'body_html' => '<html><body><p>Próba</p><p>Ez egy próba jegyzet</p></body></html>',
                'cleanup_pending' => false,
                'cleanup_pending_target_uid' => '',
            ],
        ]);
        $service->save_result = [
            'status' => 'saved',
            'selected' => [
                'note_key' => 'saved-note',
                'mailbox' => 'Notes',
                'uid' => '2',
                'uidvalidity' => '22',
                'logical_uuid' => '11111111-1111-4111-8111-111111111111',
                'message_id' => '<saved@example.invalid>',
                'updated_at' => '2026-09-12T13:05:00Z',
                'created_at' => 'Sat, 12 Sep 2026 13:05:00 +0000',
                'title' => 'Próba',
                'preview' => 'Próba Ez egy próba jegyzet',
                'body_text' => "Próba\n\nEz egy próba jegyzet",
                'body_html' => '<html><body><p>Próba</p><p>Próba</p><p>Ez egy próba jegyzet</p></body></html>',
                'cleanup_pending' => true,
                'cleanup_pending_target_uid' => '1',
            ],
        ];

        $this->setPrivate($plugin, 'rc', $rc);
        $this->setPrivate($plugin, 'content', new ImapNotesContent());
        $this->setPrivate($plugin, 'service', $service);

        $_POST = ['title' => 'Próba', 'body' => 'Ez egy próba jegyzet'];
        $plugin->action_save();

        $view_data = $this->getPrivate($plugin, 'view_data');
        $this->assertSame('Ez egy próba jegyzet', $view_data['selected']['body_text']);
        $this->assertTrue($view_data['selected']['cleanup_pending']);
        $this->assertSame('1', $view_data['selected']['cleanup_pending_target_uid']);
        $this->assertSame([['note_key' => 'saved-note', 'new_note' => false]], $service->view_calls);
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

        $this->assertHtmlMatches('/<div class="field-group"><label class="field" for="imap-notes-title-note-1-4"><span>Title<\\/span><\\/label><input class="form-control" id="imap-notes-title-note-1-4"/', $html);
        $this->assertHtmlMatches('/<div class="field-group grow"><label class="field" for="imap-notes-body-note-1-4"><span>Note<\\/span><\\/label><textarea class="form-control" id="imap-notes-body-note-1-4"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('class="main-action btn btn-primary"', $html);
        $this->assertSame(2, substr_count($html, 'class="btn btn-secondary" name="conflict_decision"'));
        $this->assertStringContainsString('name="conflict_decision" value="reload"', $html);
        $this->assertStringContainsString('name="conflict_decision" value="overwrite"', $html);
        $this->assertStringContainsString('data-confirm="Delete this note?"', $html);
        $this->assertStringContainsString('<form class="note-delete-form" method="post"', $html);
        $this->assertStringContainsString('action="?task=imap_notes&amp;action=delete"', $html);
        $this->assertStringContainsString('name="delete_step" value="prompt"', $html);
        $this->assertStringContainsString('aria-describedby="imap-notes-delete-confirm-note-1-4"', $html);
        $this->assertStringContainsString('<span class="voice" id="imap-notes-delete-confirm-note-1-4">Delete this note?</span>', $html);
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
        $this->assertHtmlMatches('/<div class="field-group"><label class="field" for="imap-notes-title-readonly-7"><span>Title<\\/span><\\/label><input class="form-control"/', $html);
        $this->assertHtmlMatches('/<div class="field-group grow"><label class="field" for="imap-notes-body-readonly-7"><span>Note<\\/span><\\/label><textarea class="form-control"/', $html);
    }

    public function testDeleteActionWithoutConfirmedFlagShowsServerSideConfirmationStep()
    {
        $plugin = new imap_notes();
        $rc = new ImapNotesPluginUiTestFakeRcmail();
        $service = new ImapNotesPluginUiTestFakeService([
            'folder' => 'Notes',
            'notes' => [],
            'selected' => [
                'note_key' => 'note-1',
                'uid' => '4',
                'mailbox' => 'Notes',
                'title' => 'My title',
                'body_text' => 'Body text',
                'body_html' => '<html><body><p>Body text</p></body></html>',
                'cleanup_pending' => false,
                'read_only' => false,
            ],
        ]);

        $this->setPrivate($plugin, 'rc', $rc);
        $this->setPrivate($plugin, 'content', new ImapNotesContent());
        $this->setPrivate($plugin, 'service', $service);

        $_POST = ['_token' => 'request-token', 'note_key' => 'note-1'];
        $plugin->action_delete();

        $view_data = $this->getPrivate($plugin, 'view_data');
        $html = $plugin->notes_editor([]);

        $this->assertSame([['note_key' => 'note-1', 'new_note' => false]], $service->view_calls);
        $this->assertSame(0, $service->delete_calls);
        $this->assertTrue($view_data['confirm_delete']);
        $this->assertSame('imap_notes.notes', $rc->output->sent_template);
        $this->assertSame(1, $rc->request_security_check_calls);
        $this->assertStringContainsString('Delete this note?', $html);
        $this->assertStringContainsString('name="delete_step" value="confirm"', $html);
        $this->assertHtmlMatches('/<form class="note-delete-form" method="post" action="\\?task=imap_notes&amp;action=delete" data-confirm="Delete this note\\?">.*<div class="imap-notes-banner warning imap-notes-delete-confirmation" role="alert" aria-live="assertive" aria-atomic="true">Delete this note\\?<\\/div>.*class="delete-button btn btn-danger">Confirm delete<\\/button>/s', $html);
        $this->assertStringContainsString('class="delete-button btn btn-danger">Confirm delete</button>', $html);
        $this->assertStringContainsString('class="button btn btn-secondary"', $html);
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

    private function assertHtmlMatches($pattern, $subject)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $subject);
            return;
        }

        $this->assertThat($subject, new PHPUnit\Framework\Constraint\RegularExpression($pattern));
    }

    private function getPrivate($object, $property)
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}

class ImapNotesPluginUiTestFakeRcmail
{
    public $task = '';
    public $output;
    public $request_security_check_calls = 0;

    public function __construct()
    {
        $this->output = new ImapNotesPluginUiTestFakeOutput();
    }

    public function url(array $params)
    {
        return '?' . http_build_query($params);
    }

    public function get_request_token()
    {
        return 'request-token';
    }

    public function request_security_check()
    {
        $this->request_security_check_calls++;

        return true;
    }
}

class ImapNotesPluginUiTestFakeOutput
{
    public $framed = false;
    public $title = null;
    public $handlers = [];
    public $sent_template = null;
    public $commands = [];

    public function command($name, $message, $type)
    {
        $this->commands[] = [$name, $message, $type];
    }

    public function set_pagetitle($title)
    {
        $this->title = $title;
    }

    public function add_handlers(array $handlers)
    {
        $this->handlers = $handlers;
    }

    public function send($template)
    {
        $this->sent_template = $template;
    }
}

class ImapNotesPluginUiTestFakeService
{
    public $view_calls = [];
    public $delete_calls = 0;
    public $save_calls = 0;
    public $save_result = [
        'status' => 'saved',
        'selected' => ['note_key' => 'saved-note'],
    ];
    private $view_result;

    public function __construct(array $view_result)
    {
        $this->view_result = $view_result;
    }

    public function view($note_key = null, $new_note = false)
    {
        $this->view_calls[] = ['note_key' => $note_key, 'new_note' => $new_note];

        return $this->view_result;
    }

    public function save(array $post, $fallback_title)
    {
        $this->save_calls++;

        return $this->save_result;
    }

    public function delete(array $post)
    {
        $this->delete_calls++;

        return [];
    }

    public function blankNote($folder)
    {
        return [];
    }
}
