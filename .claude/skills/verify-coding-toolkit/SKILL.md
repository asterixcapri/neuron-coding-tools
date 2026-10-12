---
name: verify-coding-toolkit
description: Drive the neuron-coding-tools PHP library the way a consumer does — a real Neuron AI Agent with CodingToolkit registered, only the LLM faked — to prove the read, bash, edit, write, ls, find and grep tools behave as claimed, with evidence on disk. Reach for it after changing anything under src/, before claiming a tool fix works, or when a unit test passing is not enough proof.
---

# Verify the coding toolkit

The product is a library: its user is a coding agent that calls tools. So the
real user path is a Neuron `Agent` with `CodingToolkit` registered, receiving
tool calls from a model and returning results the model reads. The harness
`scripts/drive.php` builds exactly that, replacing only the LLM with
`NeuronAI\Testing\FakeAIProvider` (the provider is the production boundary to
the external system). Each scenario call is one model turn. Tool failures go
back through the `toolErrorHandler()` the README recommends, prefixed `ERROR: `.

Paths below are relative to the repo root. Run everything from there.

## Launch

There is no server. "Launch" means making the checkout drivable once:

```bash
.claude/skills/verify-coding-toolkit/scripts/setup.sh
```

It is idempotent. It installs `php8.4` if missing (Ubuntu, ondrej PPA, needs
root), then runs `composer install`. Why it exists:

- The library needs PHP >= 8.4.1. Ubuntu 24.04's default `php` is 8.3, and
  `composer` running on it refuses the lock file. **Always call `php8.4`
  explicitly** (`php8.4 $(command -v composer) ...`), never bare `php`.
  The composer scripts (`composer test`, `cs`, `stan`) start their tools
  with the default `php` and die in `platform_check.php`; run them as
  `php8.4 vendor/bin/phpunit`, `php8.4 vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff`,
  `php8.4 vendor/bin/phpstan analyse --no-progress --memory-limit=512M`.
- In cloud sessions GitHub zipball downloads come back 403 through the proxy
  while `git` clones work. Composer falls back to clones for everything
  except `phpstan/phpstan`; the script rebuilds that zip from its git tag and
  seeds composer's cache. A clean install takes about 90 s.

Ready when `doctor.sh` prints no `FAIL`.

Each drive then runs in its own sandbox `$TMPDIR/ncto-verify-<id>/` with
`workspace/` (the toolkit's working directory) and `tmp/` (exported as
`TMPDIR`, so bash overflow logs land inside the sandbox). Runs share nothing
and can execute in parallel.

## Doctor

Read-only. Run it first, and again whenever anything looks off:

```bash
.claude/skills/verify-coding-toolkit/scripts/doctor.sh
```

It checks: `php8.4` exists and is >= 8.4.1; `intl`, `mbstring`, `posix` are
loaded; `vendor/` loads under it; `CodingToolkit` offers exactly
`read,bash,edit,write,ls,find,grep` in that order; whether `rg` is present
(decides what `--search=auto` means); whether `setsid` is present (decides
whether bash timeouts kill the process group); stale sandboxes from earlier
runs. Override the binary with `PHP=/path/to/php doctor.sh`.

## Drive

```bash
php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php <scenario.json> [--search=auto|php|rg] [--only=read,grep] [--keep]
```

- `--search` picks the file search backend for `find`/`grep`: `php`
  (`PhpFileSearch`), `rg` (`RipgrepFileSearch`), or `auto` (the toolkit's own
  selection, rg when on `PATH`). Behavior must hold on both: run search
  scenarios with `php` and `rg`.
- `--only` applies the consumer filter `CodingToolkit::only()` by tool name.
- `--keep` leaves the sandbox in place for inspection (path printed on
  stderr); `cleanup.sh` removes it later.

Exit code: `0` every expectation held, `1` one failed (listed under
`RESULT: FAIL`), `2` usage error.

Ready-made scenarios live in `scenarios/` (one per feature file in
`features/`). A scenario is JSON:

```json
{
  "prompt": "what the user asked the agent (recorded only)",
  "setup": {"files": {"relative/path": "content"}},
  "calls": [
    {"name": "edit", "inputs": {"path": "a.txt", "edits": [{"oldText": "x", "newText": "y"}]},
     "expect": {"contains": "Successfully replaced 1 block(s)"}}
  ],
  "expect": {"files": {"a.txt": "exact final content"}, "absent": ["gone.txt"], "tmpFiles": 0}
}
```

Per-call `expect` keys: `equals` (exact result string), `contains` (string or
list), `matches` (PCRE, or list), `error: true` (the call must fail; without
it a failure is itself a failed expectation), `noResult: true` (the call must
not run at all). Error results include the `ERROR: ` prefix, so `equals` and
anchored `matches` must include it too. Scenario-level `expect`: `files`
(exact bytes, so CRLF and BOM are checked), `absent`, `tmpFiles` (how many
files bash left in `TMPDIR`), `agentError` (substring of an exception that
aborted the agent run; without it any abort fails the run).

Write setup files with bytes in mind: JSON `\u0089` becomes the two UTF-8
bytes `C2 89`, not `0x89`. For binary fixtures, create them with a first
`bash` call (`printf '\x89PNG...' > logo.png`) as `scenarios/read.json` does.

## Evidence

Each run writes `.verify/evidence/<scenario>-<search>-<timestamp>-<id>/`
(gitignored, and kept by cleanup):

- `summary.txt`: PHP version, backend, model turns, workspace diff
  (`+` added, `~` changed, `-` removed), every call with the exact text the
  model received, and `RESULT: PASS|FAIL` with reasons. Quote this in a proof.
- `transcript.json`: the same calls and results, machine-readable.
- `offered-tools.json`: the tool names and parameter schemas the model was
  offered on the first turn (proves registration, order, and `--only`).
- `workspace-before.json` / `workspace-after.json`: every file's size, sha1,
  and content when it is small UTF-8 text. Side effects are proved here, not
  by the tool's own success message.
- `tmp-files.json`: what bash wrote to `TMPDIR` (overflow logs).
- `scenario.json`: the scenario as run.

Proof standards:

- Drive through `drive.php`, so calls go through the Agent's tool loop, not
  through `$tool->__invoke()`. The unit tests already cover direct calls;
  this skill exists to prove the integrated path.
- A success message is not proof of a write. Check `workspace-after.json`
  (or a scenario `expect.files`) and, when it matters, read the file back
  with a later `read` or `bash` call in the same scenario.
- For `find`/`grep`, a claim holds only when it holds on both `--search=php`
  and `--search=rg`; diff the two `summary.txt` files.
- When a change targets an edge case, add a scenario call that hits it with
  an exact `equals`/`matches`, then show it failing before the change and
  passing after.
- Report the evidence directory path with every claim.

## Cleanup

`drive.php` deletes its own sandbox on exit unless `--keep` was given, and
registers every sandbox it creates in `.verify/sandboxes/`. To remove leftovers
(after `--keep`, a crash, or a killed run):

```bash
.claude/skills/verify-coding-toolkit/scripts/cleanup.sh            # sandboxes only; evidence kept
.claude/skills/verify-coding-toolkit/scripts/cleanup.sh --evidence # also delete .verify/evidence
```

It removes only registered paths matching `ncto-verify-*`, never by process
name. A timed-out bash call is killed by the tool itself (process group via
`setsid`); `scenarios/bash.json` checks with `pgrep` that nothing survives.

## Helpers

All in `.claude/skills/verify-coding-toolkit/scripts/`, executable:

| Script | Invocation | Does |
| --- | --- | --- |
| `setup.sh` | `scripts/setup.sh` | Install PHP 8.4 and vendor/ (idempotent). |
| `doctor.sh` | `scripts/doctor.sh` | Read-only readiness checks. |
| `drive.php` | `php8.4 scripts/drive.php <scenario> [flags]` | Run a scenario through a real Agent, write evidence. |
| `cleanup.sh` | `scripts/cleanup.sh [--evidence]` | Remove registered sandboxes. |

## Feature map

`features/README.md` indexes every user-facing feature with its recipe. A
proof that drives one entry point is incomplete when the map lists others.

## Known environment issue

`composer test` has one failure in cloud containers:
`BashToolTest::testTimeoutKillsTheProcessAndItsChildrenAndIsReported` expects
`started\n\n\nCommand timed out...`, but there the captured output contains an
extra `Killed` line. The kill itself works
(no `sleep 31` survives). `scenarios/bash.json` accepts the optional
`Killed` line. Don't chase this as a regression of your change.
