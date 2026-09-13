# IMAP Notes plugin contract

## Scope

This plugin implements an MVP Roundcube task named `imap_notes` that stores notes in one configured IMAP mailbox per account.

## Configuration

```php
$config['imap_notes_folder'] = 'Notes';
```

- The configured mailbox name is resolved through Roundcube's namespace support.
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

Roundcube's message layer is responsible for runtime MIME transfer decoding and declared charset conversion when notes are read back from IMAP.

## Title derivation

- user title if present
- otherwise the first non-empty plain-text line
- otherwise `Untitled note`

## Editing model

- Editor input is plain text.
- New/edited text is canonicalized into deterministic safe HTML.
- Imported HTML is sanitized before display and before conversion back to editor text, with UTF-8 parsing explicitly declared to libxml.
- Saving imported or legacy-only notes upgrades them to the plugin-managed header set.

## Revision model

- One logical note may have multiple physical IMAP message revisions.
- The stable logical UUID survives edits unless the user chooses “save mine as a copy”.
- Newest revision selection uses `X-Roundcube-Note-Updated`, then message date as fallback.
- Older revisions with the same logical UUID are hidden by default, not auto-destroyed purely because they share the UUID.

## Conflict model

Editor session state carries:

- resolved mailbox
- UIDVALIDITY snapshot
- UID
- stable logical UUID if present
- `X-Roundcube-Note-Updated`
- folder `HIGHESTMODSEQ` snapshot when available through Roundcube
- normalized-content fingerprint

Before save, v1 checks:

1. UIDVALIDITY changes
2. current UID existence
3. stable UUID search when the original UID disappeared
4. update-header and body-fingerprint comparison on the current revision

Conflict UI actions:

- reload remote version
- overwrite with my version
- save mine as a copy

The safe default is “save mine as a copy”.

## Save semantics

1. append new revision
2. determine/confirm appended UID
3. only then mark predecessor `\Deleted`
4. use selective UID expunge only when `UIDPLUS` is available
5. otherwise leave cleanup pending instead of risking a mailbox-wide expunge

On uncertain save, users must reload before retrying.

## Delete semantics

- prefer moving the active message to the configured/account Trash mailbox
- if Trash is unavailable, mark only that UID `\Deleted`
- use selective UID expunge only when `UIDPLUS` is available
- otherwise hide the note in the plugin and leave final cleanup deferred

## Security constraints

- no arbitrary raw HTML editing
- no scripts, event handlers, forms, frames, embeds, objects, or remote images
- no unsafe URI schemes
- no attachments in v1
- list rendering exposes previews only
