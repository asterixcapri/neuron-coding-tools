# Run shell commands

The `bash` tool runs a command with `/bin/bash -c` in the toolkit's working directory and returns combined stdout and stderr. A non-zero exit code or a timeout becomes an error that still carries the output. Long output is cut to its last 2,000 lines or 50 KB, and the full output is saved to a temp file whose path is in the note.

## Sub-features

- `bash-cwd` runs in the working directory.
- `bash-exit` reports a non-zero exit as an error: `<output>\n\nCommand exited with code N`.
- `bash-tail` keeps the tail of long output and appends `[Showing lines A-B of N. Full output: <tmp>/solaro-bash-<hex>.log]`.
- `bash-timeout` kills the whole process group after `timeout` seconds: `<output>\n\nCommand timed out after N seconds`.
- `bash-empty` returns `(no output)` when the command prints nothing.
- `bash-side-effects` lets files written by the command persist in the workspace.

## How to get to it (user POV)

- The model calls `bash` with `{"command": ...}` and optionally `timeout` (seconds).

## Driving it with drive.php

Preconditions:

- `doctor.sh` is clean, including `setsid present`.

- **All sub-features.** Run `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/bash.json`. It takes about 1 s because of the timeout call. `RESULT: PASS`.
- **Working directory.** Call 0 (`pwd; ls`) prints a path ending in `/workspace` and `README.md`.
- **Exit code.** Call 1 returns an error containing `out`, `err`, and `Command exited with code 3`.
- **Tail.** Call 2 (`seq 1 3000`) starts at `1002`, ends with `[Showing lines 1002-3001 of 3001. Full output: .../solaro-bash-<16 hex>.log]`, and `tmp-files.json` lists exactly that log (`expect.tmpFiles: 1`).
- **Timeout.** Call 3 returns an error starting `ERROR: started` and ending `Command timed out after 1 seconds`. Call 4 (`pgrep -x -f 'sleep 31'`) prints `none-left`, proving the child was killed too.
- **Side effect.** Call 5 returns `(no output)`, and `workspace-after.json` contains `out.txt` with `made by bash`.

## Gotchas

- The overflow log goes to `sys_get_temp_dir()`. `drive.php` points `TMPDIR` at the sandbox's `tmp/` before PHP first reads it (PHP caches the value), so the log is observable and cleaned up. In a real consumer it lands in `/tmp` and is never deleted by the library.
- Line counts include the empty string after a trailing newline (pi behavior): 3,000 printed lines report as 3,001.
- In cloud containers, timed-out output carries an extra `Killed` line before the timeout note. `bash.json` accepts it, while the unit test `testTimeoutKillsTheProcessAndItsChildrenAndIsReported` fails on it.
- Without `setsid` only the shell is killed and children can survive. `doctor.sh` warns, and call 4 then fails.
- The working directory is not a sandbox. A command can touch anything the PHP process can. Keep scenario commands inside `workspace/`.
