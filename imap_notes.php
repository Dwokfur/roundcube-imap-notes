<?php

require_once __DIR__ . '/lib/ImapNotesContent.php';
require_once __DIR__ . '/lib/ImapNotesMessage.php';
require_once __DIR__ . '/lib/ImapNotesRevisionResolver.php';
require_once __DIR__ . '/lib/ImapNotesConflictResolver.php';
require_once __DIR__ . '/lib/ImapNotesStorageInterface.php';
require_once __DIR__ . '/lib/ImapNotesIdentityResolverInterface.php';
require_once __DIR__ . '/lib/ImapNotesService.php';
require_once __DIR__ . '/lib/ImapNotesRoundcubeStorage.php';
require_once __DIR__ . '/lib/ImapNotesIdentityResolver.php';

class imap_notes extends rcube_plugin
{
    public $task = '?(?!login|logout).*';

    private $rc;
    private $content;
    private $service;
    private $view_data = [];

    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->content = new ImapNotesContent();
        $this->service = new ImapNotesService(
            new ImapNotesRoundcubeStorage($this->rc, $this->content),
            $this->content,
            new ImapNotesMessage(),
            new ImapNotesRevisionResolver(),
            new ImapNotesConflictResolver(),
            new ImapNotesIdentityResolver($this->rc)
        );

        $this->load_config();
        $this->add_texts('localization/', false);
        $this->register_task('imap_notes');
        $this->register_action('index', [$this, 'action_index']);
        $this->register_action('save', [$this, 'action_save']);
        $this->register_action('delete', [$this, 'action_delete']);
        $this->register_action('retry_cleanup', [$this, 'action_retry_cleanup']);
        $this->add_hook('startup', [$this, 'startup']);
    }

    public function startup($args)
    {
        if (!$this->rc->output->framed) {
            $this->add_button([
                'command' => 'imap_notes',
                'class' => 'button-notes',
                'classsel' => 'button-notes button-selected',
                'innerclass' => 'button-inner',
                'label' => 'imap_notes.notes',
                'type' => 'link',
            ], 'taskbar');
        }

        $this->include_stylesheet($this->local_skin_path() . '/imap_notes_taskbar.css');
        if ($this->rc->task === 'imap_notes') {
            $this->include_stylesheet($this->local_skin_path() . '/imap_notes.css');
            $this->include_script($this->local_skin_path() . '/imap_notes.js');
        }

        return $args;
    }

    public function action_index()
    {
        try {
            $this->view_data = $this->service->view(rcube_utils::get_input_string('_note', rcube_utils::INPUT_GPC));
        } catch (Exception $e) {
            $this->view_data = $this->fallbackViewData();
            $this->rc->output->command('display_message', $this->localizeError($e->getMessage()), 'error');
        }

        $this->renderIndex();
    }

    public function action_save()
    {
        try {
            $result = $this->service->save($_POST, $this->gettext('untitlednote'));
            if ($result['status'] === 'reloaded') {
                $view = $this->service->view();
                $view['selected'] = $result['selected'];
            } else {
                $view = $this->service->view($result['selected']['note_key'] ?? null);
                $view['selected'] = array_merge($view['selected'], $result['selected'] ?? []);
            }
            if (!empty($result['conflict'])) {
                $view['conflict'] = $result['conflict'];
            }
            $this->view_data = $view;
            $this->rc->output->command('display_message', $this->gettextForResult($result), $this->messageType($result['status']));
        } catch (Exception $e) {
            $this->view_data = $this->fallbackViewData();
            $this->rc->output->command('display_message', $this->localizeError($e->getMessage()), 'error');
        }

        $this->renderIndex();
    }

    public function action_delete()
    {
        try {
            $result = $this->service->delete($_POST);
            $this->view_data = [
                'folder' => $result['folder'],
                'notes' => $result['notes'],
                'selected' => $result['selected'],
            ];
            if (!empty($result['conflict'])) {
                $this->view_data['conflict'] = $result['conflict'];
            }
            $this->rc->output->command('display_message', $this->gettextForResult($result), $this->messageType($result['status']));
        } catch (Exception $e) {
            $this->view_data = $this->fallbackViewData();
            $this->rc->output->command('display_message', $this->localizeError($e->getMessage()), 'error');
        }

        $this->renderIndex();
    }

    public function action_retry_cleanup()
    {
        try {
            $result = $this->service->retryCleanup($_POST);
            $this->view_data = [
                'folder' => $result['folder'],
                'notes' => $result['notes'],
                'selected' => $result['selected'],
            ];
            $this->rc->output->command('display_message', $this->gettextForResult($result), $this->messageType($result['status']));
        } catch (Exception $e) {
            $this->view_data = $this->fallbackViewData();
            $this->rc->output->command('display_message', $this->localizeError($e->getMessage()), 'error');
        }

        $this->renderIndex();
    }

    public function notes_list($attrib)
    {
        $out = '<div class="imap-notes-sidebar">';
        $out .= '<div class="imap-notes-folder">' . $this->escape($this->view_data['folder']) . '</div>';
        $out .= '<a class="button create" href="' . $this->escape($this->rc->url(['task' => 'imap_notes', 'action' => 'index'])) . '">' . $this->escape($this->gettext('newnote')) . '</a>';
        $out .= '<ul class="listing imap-notes-list">';

        $selected_key = $this->view_data['selected']['note_key'] ?? '';
        foreach ((array) $this->view_data['notes'] as $index => $note) {
            $classes = ['imap-note-item'];
            $status_id = '';
            $attributes = [];
            if ($note['note_key'] === $selected_key) {
                $classes[] = 'selected';
                $attributes[] = ' aria-current="page"';
            }
            if (!empty($note['cleanup_pending'])) {
                $classes[] = 'cleanup-pending';
                $status_id = $this->domId('imap-note-status', ($note['note_key'] ?? '') . '-' . $index);
                $attributes[] = ' aria-describedby="' . $this->escape($status_id) . '"';
            }

            $out .= '<li class="' . implode(' ', $classes) . '">';
            $out .= '<a href="' . $this->escape($this->rc->url([
                'task' => 'imap_notes',
                'action' => 'index',
                '_note' => $note['note_key'],
            ])) . '" aria-label="' . $this->escape($note['title']) . '"' . implode('', $attributes) . '>';
            $out .= '<span class="title">' . $this->escape($note['title']) . '</span>';
            $out .= '<span class="preview">' . $this->escape($note['preview']) . '</span>';
            if ($status_id !== '') {
                $out .= '<span class="voice" id="' . $this->escape($status_id) . '">' . $this->escape($this->gettext('cleanuppendinglabel')) . '</span>';
            }
            $out .= '</a>';
            $out .= '</li>';
        }

        $out .= '</ul></div>';

        return $out;
    }

    public function notes_editor($attrib)
    {
        $note = $this->view_data['selected'];
        $conflict = $this->view_data['conflict'] ?? null;
        $save_url = $this->rc->url(['task' => 'imap_notes', 'action' => 'save']);
        $delete_url = $this->rc->url(['task' => 'imap_notes', 'action' => 'delete']);
        $cleanup_url = $this->rc->url(['task' => 'imap_notes', 'action' => 'retry_cleanup']);
        $token = $this->rc->get_request_token();
        $title = $this->escape($note['title']);
        $body = htmlspecialchars($note['body_text'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $read_only = !empty($note['read_only']);
        $read_only_html = preg_replace(
            '/^<html><body>|<\\/body><\\/html>$/',
            '',
            $this->content->sanitizeHtml((string) ($note['body_html'] ?? ''))
        );
        $preview_id = 'imap-notes-rendered-preview';
        $field_suffix = $this->domIdSuffix(($note['note_key'] ?? '') . '-' . ($note['uid'] ?? 'new'));
        $title_id = 'imap-notes-title-' . $field_suffix;
        $body_id = 'imap-notes-body-' . $field_suffix;

        $out = '<div class="imap-notes-editor">';
        if ($conflict) {
            $out .= '<div class="imap-notes-banner warning" role="alert" aria-live="assertive" aria-atomic="true">';
            $out .= '<strong>' . $this->escape($this->gettext('conflicttitle')) . '</strong>';
            $out .= '<p>' . $this->escape($this->gettext('conflictmessage')) . '</p>';
            $out .= '<p class="remote-title">' . $this->escape($conflict['title']) . '</p>';
            $out .= '</div>';
        }

        if ($read_only && !empty($note['read_only_reason'])) {
            $out .= '<div class="imap-notes-banner warning" role="status" aria-live="polite" aria-atomic="true">' . $this->escape($note['read_only_reason']) . '</div>';
        }

        if (!empty($note['cleanup_pending'])) {
            $out .= '<div class="imap-notes-banner info" role="status" aria-live="polite" aria-atomic="true">' . $this->escape($this->gettext('revisioncleanuppending')) . '</div>';
        }

        $out .= '<form class="note-form" method="post" action="' . $this->escape($save_url) . '">';
        $out .= $this->hidden('_token', $token);
        foreach (['note_key', 'mailbox', 'uid', 'uidvalidity', 'logical_uuid', 'message_id', 'updated_at', 'created_at', 'fingerprint'] as $field) {
            $out .= $this->hidden($field, $note[$field] ?? '');
        }
        $out .= $this->hidden('read_only', $read_only ? '1' : '0');
        $out .= '<div class="field-group"><label class="field" for="' . $this->escape($title_id) . '"><span>' . $this->escape($this->gettext('title')) . '</span></label><input id="' . $this->escape($title_id) . '" type="text" name="title" value="' . $title . '"' . ($read_only ? ' readonly="readonly"' : '') . ' /></div>';
        $out .= '<div class="field-group grow"><label class="field" for="' . $this->escape($body_id) . '"><span>' . $this->escape($this->gettext('body')) . '</span></label><textarea id="' . $this->escape($body_id) . '" name="body" rows="18"' . ($read_only ? ' readonly="readonly"' : '') . '>' . $body . '</textarea></div>';
        if ($read_only && $read_only_html !== '') {
            $out .= '<div class="imap-notes-rendered" role="region" aria-labelledby="' . $preview_id . '"><div class="label" id="' . $preview_id . '">' . $this->escape($this->gettext('renderedpreview')) . '</div>' . $read_only_html . '</div>';
        }
        $out .= '<div class="actions">';
        if ($conflict) {
            $out .= '<button type="submit" class="main-action" name="conflict_decision" value="copy">' . $this->escape($this->gettext('savemycopy')) . '</button>';
            $out .= '<button type="submit" name="conflict_decision" value="reload">' . $this->escape($this->gettext('reloadremote')) . '</button>';
            $out .= '<button type="submit" name="conflict_decision" value="overwrite">' . $this->escape($this->gettext('overwrite')) . '</button>';
        } elseif (!$read_only) {
            $out .= '<button type="submit" class="main-action">' . $this->escape($this->gettext('save')) . '</button>';
        }
        $out .= '</div></form>';

        if (!empty($note['uid']) && empty($note['read_only'])) {
            $delete_confirm_id = 'imap-notes-delete-confirm-' . $field_suffix;
            $out .= '<form class="note-delete-form" method="post" action="' . $this->escape($delete_url) . '" data-confirm="' . $this->escape($this->gettext('deleteconfirm')) . '" aria-describedby="' . $this->escape($delete_confirm_id) . '">';
            $out .= $this->hidden('_token', $token);
            foreach (['note_key', 'mailbox', 'uid', 'uidvalidity', 'logical_uuid', 'message_id', 'updated_at', 'created_at', 'fingerprint'] as $field) {
                $out .= $this->hidden($field, $note[$field] ?? '');
            }
            $out .= '<span class="voice" id="' . $this->escape($delete_confirm_id) . '">' . $this->escape($this->gettext('deleteconfirm')) . '</span>';
            $out .= '<button type="submit" class="delete-button" aria-describedby="' . $this->escape($delete_confirm_id) . '">' . $this->escape($this->gettext('delete')) . '</button>';
            $out .= '</form>';
        }

        if (!empty($note['cleanup_pending'])) {
            $out .= '<form class="note-cleanup-form" method="post" action="' . $this->escape($cleanup_url) . '">';
            $out .= $this->hidden('_token', $token);
            $out .= $this->hidden('note_key', $note['note_key'] ?? '');
            $out .= $this->hidden('uid', !empty($note['cleanup_pending_target_uid']) ? $note['cleanup_pending_target_uid'] : ($note['uid'] ?? ''));
            $out .= $this->hidden('cleanup_pending_target_uid', $note['cleanup_pending_target_uid'] ?? '');
            $out .= '<button type="submit">' . $this->escape($this->gettext('retrycleanup')) . '</button>';
            $out .= '</form>';
        }

        $out .= '</div>';

        return $out;
    }

    private function renderIndex()
    {
        $this->rc->output->set_pagetitle($this->gettext('notes'));
        $this->rc->output->add_handlers([
            'imap_notes_list' => [$this, 'notes_list'],
            'imap_notes_editor' => [$this, 'notes_editor'],
        ]);
        $this->rc->output->send('imap_notes.notes');
    }

    private function hidden($name, $value)
    {
        return '<input type="hidden" name="' . $this->escape($name) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '" />';
    }

    private function escape($value)
    {
        return rcube::Q((string) $value);
    }

    private function domId($prefix, $value)
    {
        return $prefix . '-' . $this->domIdSuffix($value);
    }

    private function domIdSuffix($value)
    {
        $value = preg_replace('/[^a-z0-9]+/i', '-', (string) $value);
        $value = trim((string) $value, '-');

        return $value !== '' ? strtolower($value) : 'item';
    }

    private function messageType($status)
    {
        if ($status === 'error') {
            return 'error';
        }

        if (in_array($status, ['conflict', 'cleanup_pending', 'delete_cleanup_pending'], true)) {
            return 'warning';
        }

        return 'confirmation';
    }

    private function gettextForResult(array $result)
    {
        if (!empty($result['message']) && in_array($result['status'], ['error', 'conflict', 'cleanup_pending'], true)) {
            return $result['message'];
        }

        $map = [
            'saved' => 'saved',
            'saved_copy' => 'savedcopy',
            'deleted' => 'deleted',
            'delete_cleanup_pending' => 'deletecleanuppending',
            'cleanup_pending' => 'cleanuppending',
            'cleaned' => 'cleanupdone',
            'reloaded' => 'reloaded',
            'conflict' => 'conflictmessage',
        ];

        if (!empty($result['message']) && empty($map[$result['status']])) {
            return $result['message'];
        }

        $label = $map[$result['status']] ?? null;

        return $label ? $this->gettext($label) : (string) ($result['message'] ?? '');
    }

    private function localizeError($message)
    {
        if (strpos($message, 'configured notes mailbox') !== false) {
            return sprintf($this->gettext('foldererror'), $this->rc->config->get('imap_notes_folder', 'Notes'));
        }
        if (strpos($message, 'valid default identity') !== false) {
            return $this->gettext('identityerror');
        }

        return $message;
    }

    private function fallbackViewData()
    {
        return [
            'folder' => '',
            'notes' => [],
            'selected' => $this->service->blankNote(''),
        ];
    }
}
