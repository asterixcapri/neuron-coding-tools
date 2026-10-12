# Explore and search

`ls` lists one directory (dotfiles included, directories suffixed with `/`). `find` matches files by glob. `grep` searches contents with regex or literal patterns, glob filters, case options, and context. `find` and `grep` share a file search backend: ripgrep when `rg` is on `PATH`, otherwise pure PHP. Both backends must produce the same results for the same ignore rules.

## Sub-features

- `ls-list` lists entries sorted, with dotfiles and `name/` for directories.
- `find-glob` returns matching paths relative to the search root, and `No files found matching pattern` when none match.
- `find-path` searches under a given `path`.
- `grep-regex` returns `path:line: text` lines.
- `grep-options` covers `ignoreCase`, `glob`, `literal`, and a single-file `path`.
- `grep-empty` returns `No matches found`.
- `search-ignore` excludes `.git` and paths in the root `.gitignore` on both backends.

## How to get to it (user POV)

- The model calls `ls` with optional `path` and `limit`.
- The model calls `find` with `pattern` and optional `path` and `limit`.
- The model calls `grep` with `pattern` and optional `path`, `glob`, `ignoreCase`, `literal`, `context`, `limit`.

## Driving it with drive.php

Preconditions:

- `doctor.sh` is clean and reports a ripgrep version.

- **PHP backend.** Run `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/search.json --search=php`. `RESULT: PASS`.
- **Ripgrep backend.** Run the same command with `--search=rg`. `RESULT: PASS`.
- **Parity.** Diff the call sections of both `summary.txt` files (from the first `--- call` line to `RESULT`). They must be identical.
- **Ignore rules.** Call 1 (`find **/*.php`) returns exactly `src/App.php\nsrc/Util/Str.php`, and call 3 (`grep TODO`) returns only `src/App.php:4:     // TODO: boot`, so the gitignored `build/App.php` is absent from both.
- **Options.** Call 4 (`todo`, `ignoreCase`, `glob *.php`) hits both PHP files. Call 5 (`a.b`, `literal`, `path notes.md`) returns only `notes.md:1: a.b literal`.
- **Empty.** Calls 2 and 6 return `No files found matching pattern` and `No matches found`.
- **Proof.** Report both evidence directories.

## Gotchas

- `ls` does not apply ignore rules: `build/` and `.env` appear. That is intended.
- The backends differ where the README says they do. ripgrep honors nested `.gitignore` files and uses its own regex dialect, while the PHP backend reads only the root `.gitignore`, uses PCRE, skips symlinks, and always excludes `vendor` and `node_modules`. A parity diff on those cases is expected. Don't count it as a regression without checking the README.
- `--search=auto` picks rg whenever it is on `PATH`, so an `auto` run proves nothing about the PHP backend.
