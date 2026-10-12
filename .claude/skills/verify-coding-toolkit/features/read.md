# Read files

The `read` tool returns a text file's lines, paged with `offset` and `limit`, and appends a note telling the model how to continue when it stops early. Binary files are described, not returned. Failures (a missing file, an offset past the end) come back as errors the model can recover from.

## Sub-features

- `read-full` returns a short file verbatim.
- `read-page` stops at 2,000 lines (or 50 KB) and appends `[Showing lines 1-2000 of N. Use offset=2001 to continue.]`.
- `read-range` honors `offset` (1-based) and `limit`.
- `read-past-end` errors when `offset` is beyond the last line.
- `read-missing` errors with `File not found: <path>`.
- `read-binary` describes images (`image/png`, `image/jpeg`, `image/gif`, `image/webp`) and NUL-containing files (`application/octet-stream`) as `Binary file (<type>, <size>); content not shown.`

## How to get to it (user POV)

- The model calls `read` with `{"path": ...}` and optionally `offset` and `limit`.
- Paths are relative to the toolkit's working directory, or absolute, or start with `~`.

## Driving it with drive.php

Preconditions:

- `doctor.sh` is clean.

- **All sub-features.** Run `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/read.json`. It creates `lines.txt` (`seq 1 2500`), `logo.png`, and `blob.bin` through a first `bash` call, then reads them. `summary.txt` ends with `RESULT: PASS`.
- **Page note.** Call 1's result ends with `[Showing lines 1-2000 of 2501. Use offset=2001 to continue.]`.
- **Range.** Call 2 (`offset: 2499, limit: 5`) returns exactly `2499\n2500\n`.
- **Errors.** Call 3 returns `ERROR: Offset 9999 is beyond end of file (2501 lines total)` and call 4 returns `ERROR: File not found: missing.txt`.
- **Binary.** Calls 5 and 6 return `Binary file (image/png, 12B); content not shown.` and `Binary file (application/octet-stream, 5B); content not shown.`
- **Proof.** `summary.txt` lists `workspace changes` as `~ lines.txt`, `+ blob.bin`, `+ logo.png` (all from the setup `bash` call), so the reads wrote nothing.

## Gotchas

- Line counts include the empty string after a trailing newline, as in pi: `seq 1 2500` reads as 2,501 lines. That is ported behavior, not an off-by-one to fix in a scenario.
- A missing, zero, or negative `offset` means the start of the file.
- Binary fixtures must be written as bytes (via `bash printf`). JSON `\u0089` in `setup.files` is UTF-8 encoded and no longer matches the PNG signature.
- `read` loads the whole file into memory even when it returns one page. Don't build multi-GB fixtures.
