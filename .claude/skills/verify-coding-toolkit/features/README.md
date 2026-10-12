# Coding toolkit verification map

This directory is the maintained source for verifying the user-facing behavior of `neuron-coding-tools`. Its user is a coding agent: a Neuron AI `Agent` that has `CodingToolkit` registered and sends tool calls. Read this index before driving, then use the matching feature file as the recipe.

## Baseline preconditions

- `scripts/setup.sh` has run, and `scripts/doctor.sh` prints no `FAIL`.
- Commands run from the repo root with `php8.4`, never bare `php` (it may be 8.3).
- `drive.php` creates a fresh sandbox per run. Never point a scenario at a real project directory.

## Driving conventions

- Every drive is `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php <scenario> [--search=php|rg] [--only=...]`.
- A scenario's calls are model turns in order. Later calls see the effects of earlier ones, so read-back checks go after the mutation in the same scenario.
- Tool failures reach the model as `ERROR: <message>` through the agent's `toolErrorHandler()`. Expect them with `"error": true`.
- `find` and `grep` claims are run on both `--search=php` and `--search=rg`.

## Proof and skip reporting

- Proof is the run's `.verify/evidence/<run>/summary.txt` with `RESULT: PASS`, plus the specific lines that show the behavior.
- Mutation proof includes `workspace-after.json` (or `expect.files`), not only the tool's success message.
- Record the scenario, backend, and evidence path with every claim.
- If ripgrep is absent, report `--search=rg` as not verified. Do not count the PHP run as covering it.

## Feature entry contract

Each feature file starts with an H1 title and one paragraph describing the behavior the agent sees. It then has exactly four H2 sections in this order: `Sub-features`, `How to get to it (user POV)`, `Driving it with drive.php`, `Gotchas`.

## Features

- [Read files](./read.md): text paging, truncation notes, out-of-range offsets, missing files, binary detection. Scenario `scenarios/read.json`.
- [Edit and write files](./edit-write.md): multi-block replacements against the original, line-ending preservation, no-match errors, file creation with parents. Scenarios `scenarios/edit.json` and `scenarios/search.json` (write).
- [Run shell commands](./bash.md): working directory, exit codes, tail truncation with an overflow log, timeouts that kill the tree. Scenario `scenarios/bash.json`.
- [Explore and search](./search.md): `ls`, `find`, `grep` on both search backends, ignore rules, empty results. Scenario `scenarios/search.json`.
- [Toolkit registration](./toolkit.md): the tool set and order offered to the model, `only()` filtering, calls to unregistered tools. Scenario `scenarios/toolkit.json`.
