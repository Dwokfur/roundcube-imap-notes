# IMAP Notes plugin contract

## Scope

This plugin implements an MVP Roundcube task named `imap_notes` that stores notes in one configured IMAP mailbox per account.

## Configuration

```php
$config['imap_notes_folder'] = 'Notes';
```

- The configured mailbox name is resolved through Roundcube's public namespace-aware folder transformation API (`mod_folder($configured, 'in')`) with an existing-folder fast path.
- No automatic migration is attempted when the configured mailbox changes.

## Accepted note sources

The notes task lists these mailbox messages:

1. Roundcube-managed notes with `X-Roundcube-Note-Version: 1`
2. legacy Apple-marked notes with `X-Uniform-Type-Identifier: com.apple.mail-note`
3. generic `text/plain` or `text/html` messages in the mailbox

Unsupported multipart messages or messages with attachments are shown read-only in v1.

## Plugin-written message format

Each plugin-written physical revision is a single message with:

- `Content-Type: text/html; charset=UTF-8`
- `Content-Transfer-Encoding: quoted-printable`
- `X-Uniform-Type-Identifier: com.apple.mail-note`
- `X-Universally-Unique-Identifier: <stable UUID>`
- `X-Roundcube-Note-Version: 1`
- `X-Roundcube-Note-Updated: <ISO-8601 UTC timestamp>`
- `Message-ID: <fresh UUID-based ID>`
- `Date: <RFC 5322 date>`
- `Subject: <title>`
- `From: <server-derived Roundcube default identity>`
- `X-Mail-Created-Date: <logical creation RFC 5322 date>`

`X-Roundcube-Note-Version: 1` remains an additional plugin marker and does not replace Apple-oriented metadata. The plugin never writes a `To` header.

Roundcube's message layer is responsible for runtime MIME transfer decoding and declared charset conversion when notes are read back from IMAP.

## Title derivation

- user title if present
- otherwise the first non-empty plain-text line
- otherwise `Untitled note`

## Editing model

- Editor input is plain text.
- New/edited text is canonicalized into deterministic safe HTML.
- For Apple-legacy compatibility, newly written plugin notes duplicate the title as Subject and as the first visible body line, followed by a blank visible line, then the editable body.
- Imported HTML is sanitized before display and before conversion back to editor text, with UTF-8 parsing explicitly declared to libxml.
- Saving imported or legacy-only notes upgrades them to the plugin-managed header set.
- Duplicate-title stripping from imported body text is applied only to Apple-marked or plugin-managed compatible notes when the first non-empty visible body line equals Subject after Unicode whitespace normalization.

## Revision model

- One logical note may have multiple physical IMAP message revisions.
- The stable logical UUID survives edits unless the user chooses “save mine as a copy”.
- `X-Mail-Created-Date` is set on logical note creation and preserved on later revisions of the same logical UUID.
- Newest revision selection uses `X-Roundcube-Note-Updated`, then message date as fallback.
- Older revisions with the same logical UUID are hidden by default, not auto-destroyed purely because they share the UUID.

## Conflict model

Editor session state carries:

- encoded `note_key`
- UIDVALIDITY snapshot
- current UID snapshot
- stable logical UUID if present
- original `Message-ID` if present
- `X-Roundcube-Note-Updated`
- normalized-content fingerprint

Before save, v1 performs best-effort checks:

1. UIDVALIDITY changes
2. current UID existence
3. stable UUID search when the original UID disappeared
4. `Message-ID`, logical UUID, update-header, and body-fingerprint comparison on the current revision

Conflict UI actions:

- reload remote version
- overwrite with my version
- save mine as a copy

The safe default is “save mine as a copy”.

This is not true conditional IMAP protection. v1 does not send per-message CONDSTORE/`UNCHANGEDSINCE`; folder `HIGHESTMODSEQ` is not treated as authoritative for mutation safety.

## Save semantics

1. append new revision
2. determine/confirm appended UID
3. only then revalidate the predecessor revision by decoded `note_key`, configured-folder match, UIDVALIDITY, stable UUID, and `Message-ID`
4. mark only that predecessor UID `\Deleted`
5. use selective UID expunge only when `UIDPLUS` is available
6. otherwise leave cleanup pending instead of risking a mailbox-wide expunge

On uncertain save, users must reload before retrying.

## Delete semantics

- decode and validate the submitted `note_key` before any destructive action
- reject any note key that points outside the configured notes mailbox
- reload the target UID from IMAP and require UIDVALIDITY plus `Message-ID`/logical-UUID state to still match
- for notes with a stable logical UUID, require the target revision to still be the current active revision; stale deletes reload instead of deleting an older physical message
- prefer moving the validated active message to the configured/account Trash mailbox
- if Trash is unavailable, mark only that UID `\Deleted`
- use selective UID expunge only when `UIDPLUS` is available
- otherwise hide the note in the plugin and leave final cleanup deferred through a structured per-folder cleanup record

## Security constraints

- no arbitrary raw HTML editing
- no scripts, event handlers, forms, frames, embeds, objects, or remote images
- no unsafe URI schemes
- no attachments in v1
- list rendering exposes previews only
- malformed or untrusted logical UUID values must not be interpolated into raw IMAP `HEADER` searches
- a valid Roundcube default identity email is required before save; sender identity is derived server-side and not accepted from request input
