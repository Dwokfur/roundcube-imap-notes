# roundcube-imap-notes

`imap_notes` is a standalone Roundcube plugin that stores one logical note as IMAP message revisions in a single configured mailbox. It targets legacy Apple Mail/iOS IMAP-note conventions (not guaranteed modern iCloud Notes synchronization) while keeping ordinary-message visibility in Thunderbird.

## Assumptions

- Roundcube: current maintained releases with the classic plugin API (tested statically against the 1.6-era plugin/storage APIs).
- PHP: 8.1+ recommended.
- IMAP: one writable mailbox per account for notes, configured by the administrator.

## Installation

1. Copy this repository into Roundcube's `plugins/imap_notes` directory.
2. Copy `config.inc.php.dist` to `config.inc.php` if you need a non-default mailbox name.
3. Enable the plugin in Roundcube's main `config/config.inc.php`:

```php
$config['plugins'][] = 'imap_notes';
```

## Configuration

The plugin exposes one required setting only:

```php
$config['imap_notes_folder'] = 'Notes';
```

The configured name is resolved with Roundcube's IMAP namespace support. The plugin never hard-codes `/` or `.` as a hierarchy delimiter.

## What it does

- Adds a dedicated **Notes** task to the Roundcube taskbar.
- Lists notes from the configured mailbox.
- Opens, creates, saves, deletes, and retries deferred cleanup for notes.
- Stores plugin-managed notes as UTF-8 quoted-printable HTML IMAP messages (`Content-Type: text/html; charset=UTF-8`).
- Accepts plugin-managed notes, legacy Apple-marked notes, and generic `text/plain`/`text/html` messages in the notes mailbox.
- Shows unsupported multipart/attachment-bearing messages read-only instead of silently rewriting them.

## Storage format

Each plugin-written revision is a single HTML message with these headers:

- `X-Uniform-Type-Identifier: com.apple.mail-note`
- `X-Universally-Unique-Identifier: <stable UUID>`
- `X-Roundcube-Note-Version: 1`
- `X-Roundcube-Note-Updated: <ISO-8601 UTC timestamp>`
- `Message-ID: <fresh UUID-based ID>`
- `Date: <current RFC 5322 date>`
- `Subject: <title>`
- `From: <Roundcube default identity (required, server-derived)>`
- `X-Mail-Created-Date: <logical creation RFC 5322 date>`

At runtime, IMAP transfer decoding and declared-body charset conversion are performed by Roundcube's message layer before the plugin sanitizes or renders note content.

The plugin never writes a `To` header.
`X-Mail-Created-Date` remains stable across revisions of the same logical note, while `Date`, `Message-ID`, and `X-Roundcube-Note-Updated` continue to change per physical revision.

Title fallback order:

1. explicit user title
2. first non-empty plain-text body line
3. `Untitled note`

## Save, revision, conflict, and cleanup model

- Notes are immutable IMAP messages from the plugin's perspective.
- Saving appends a new revision first.
- If `APPENDUID` is available through Roundcube's storage layer, the new UID is used directly.
- Otherwise the plugin performs a conservative lookup by fresh `Message-ID`, logical UUID, and update timestamp.
- Only after the new revision is identified does the plugin attempt to retire the predecessor revision.
- Destructive operations never trust posted hidden fields alone. The plugin decodes the submitted `note_key`, re-resolves the configured notes folder server-side, reloads the target UID from IMAP, and re-checks UIDVALIDITY plus revision identity headers before mutating anything.
- User-initiated delete requires the selected revision to still be the current active revision for its stable logical UUID when one is present; stale deletes are rejected with a reload/conflict outcome instead of silently deleting an older physical message.
- Deferred cleanup is tracked as structured per-folder records keyed by resolved mailbox and UID, including UIDVALIDITY, logical UUID, and `Message-ID`, so a reused UID is never blindly marked `\Deleted`.
- If the server advertises `UIDPLUS`, the plugin requests selective UID expunge for that predecessor.
- If safe removal cannot be finished, the newest revision remains visible and cleanup is marked as pending.
- Duplicate logical UUIDs are resolved by displaying only the newest revision by note-update timestamp, with message date fallback.

Conflict handling is best-effort, not a true conditional IMAP write. The plugin does not issue per-message CONDSTORE/`UNCHANGEDSINCE` operations; it relies on UIDVALIDITY, current-UID existence, `Message-ID`, logical UUID, note-update headers, and normalized-content fingerprints to detect likely conflicts.

Save conflicts block silent overwrite and offer:

- reload remote version
- overwrite with my version
- save mine as a copy

Saving as a copy creates a new logical UUID.

Delete conflicts do not offer overwrite semantics: the plugin reloads the current server state and requires the user to retry explicitly.

## Security model

- Editing is plain-text-first: users type text, not raw HTML.
- New and edited note bodies are canonicalized into deterministic UTF-8 HTML paragraphs and line breaks, with the title duplicated intentionally as Subject and as the first visible body line (followed by a blank line) for legacy Apple compatibility.
- Imported HTML is sanitized before display/import, and the sanitizer parses supplied HTML explicitly as UTF-8.
- The sanitizer strips scripts, event handlers, forms, frames, embeds, objects, styles, remote-loading image tags, and unsafe URI schemes.
- The plugin does not guess-repair mojibake when the input is already valid UTF-8.
- The list UI exposes only title/preview snippets, not full note bodies.
- The plugin does not log or surface credentials.

## Limitations

- v1 does not support attachments, tags, nested notebooks, or HTML merge.
- Plugin writes single-part HTML only; imported multipart/attachment-bearing messages remain readable but read-only in v1.
- Changing `imap_notes_folder` does not migrate existing notes.
- The plugin aims for legacy Apple Mail IMAP-notes compatibility, not modern iCloud Notes synchronization.
- When Trash is unavailable and `UIDPLUS` is unavailable, final deletion cleanup may remain deferred.
- Roundcube's public storage APIs make folder-level mod-sequence data more accessible than full conditional note writes, so v1 uses best-effort revision checks instead of claiming per-message CONDSTORE protection.

## Testing

Run the unit tests:

```bash
phpunit
```

Fixture `.eml` tests under `tests/fixtures/apple` validate the plugin compatibility contract (headers, normalization, multipart read-only behavior), but do not guarantee every historical Apple client/server variant.

Syntax-check the plugin files:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Manual accessibility/UI verification checklist

- [ ] Elastic light mode: New note, Title, Body, banners, selected notes, and rendered preview use theme-appropriate colors and readable contrast.
- [ ] Elastic dark mode: New note, Title, Body, banners, selected notes, and rendered preview no longer show white browser-default surfaces.
- [ ] Keyboard navigation: visible `:focus-visible` states appear on note links, New note, Save/Delete/Retry buttons, Title, and Body controls.
- [ ] Screen-reader semantics: Title/Body labels are associated with their controls; the selected note exposes `aria-current`; conflict feedback is announced as an alert; read-only and cleanup-pending notices are polite status updates.
- [ ] Delete flow: deleting a note shows the localized confirmation prompt before submitting.
- [ ] Responsive layout: at 320px width and at 200% zoom, the sidebar button remains usable, action buttons wrap or stack cleanly, long folder/note text does not cause horizontal overflow, and rendered `pre`/`code` content wraps or scrolls within its panel.

## Manual Dovecot verification checklist

In a real Roundcube + Dovecot environment, verify:

- `CAPABILITY` includes or omits `UIDPLUS` as expected.
- Appends expose `APPENDUID` when supported.
- Selective `UID EXPUNGE` works when `UIDPLUS` is present.
- Reload/conflict behavior still works correctly without relying on per-message `CONDSTORE`/`UNCHANGEDSINCE`.
- The configured notes mailbox is auto-created when absent and permissions allow it.
- Legacy Apple-marked notes and generic plain/html messages in the folder are listed safely.

## Ordinary-message interoperability

Thunderbird should still see the underlying IMAP messages as normal mail messages in the configured folder. Saving an imported generic message through the plugin rewrites it into the plugin's HTML note format while preserving or assigning a logical UUID as needed.
