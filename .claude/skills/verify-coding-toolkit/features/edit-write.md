# Edit and write files

`edit` applies one or more `{oldText, newText}` replacements in a single call, each matched against the original file, unique and non-overlapping, and keeps the file's BOM and LF/CRLF style. `write` creates or overwrites a file and creates missing parent directories. Both report success with a short message. The proof is the file on disk.

## Sub-features

- `edit-multi` applies several replacements in one call: `Successfully replaced N block(s) in <path>.`
- `edit-crlf` keeps CRLF line endings when the replacement text uses LF.
- `edit-no-match` errors with `Could not find the exact text in <path>...` and leaves the file untouched.
- `edit-fuzzy` falls back to a Unicode/whitespace-normalized match when exact matching fails. That normalization then applies to the whole file.
- `write-create` creates a file and its missing parent directories: `Successfully wrote N bytes to <path>`.

## How to get to it (user POV)

- The model calls `edit` with `{"path": ..., "edits": [{"oldText": ..., "newText": ...}, ...]}`.
- The model calls `write` with `{"path": ..., "content": ...}`.

## Driving it with drive.php

Preconditions:

- `doctor.sh` is clean.

- **Edit flow.** Run `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/edit.json`. It seeds a CRLF `app.php`, reads it, renames `greet` to `welcome` with three edits in one call, tries an edit that no longer matches, then runs `php app.php`. `RESULT: PASS`.
- **Multi-block.** Call 1 returns `Successfully replaced 3 block(s) in app.php.`
- **No match.** Call 2 returns `ERROR: Could not find the exact text in app.php. The old text must match exactly including all whitespace and newlines.`
- **Behavior after edit.** Call 3 (`bash php app.php`) prints `Welcome!`, so the edited file still parses and runs.
- **Line endings.** The scenario's `expect.files` compares `app.php` byte for byte with `\r\n` endings. `workspace-after.json` shows the content.
- **Write.** Run `scenarios/search.json`. Call 7 returns `Successfully wrote 6 bytes to docs/deep/new.md`, and `workspace-after.json` contains `docs/deep/new.md` with `# New\n`.

## Gotchas

- Every `oldText` is matched against the original file, not against the result of earlier edits in the same call. An edit that targets text introduced by a sibling edit fails.
- Overlapping or duplicate `oldText` values are rejected. A scenario that wants to test that must set `"error": true`.
- `edit-fuzzy` is not covered by a shipped scenario yet. To verify it, seed a file with non-breaking spaces or smart quotes and send ASCII `oldText`, then check the whole file in `workspace-after.json`, since normalization rewrites more than the matched region.
- Concurrent writes to the same file are not serialized. Don't verify that.
