# Toolkit registration

Registering `CodingToolkit` on an agent offers the model seven tools in a fixed order: `read`, `bash`, `edit`, `write`, `ls`, `find`, `grep`, each with a JSON parameter schema. Consumers can narrow the set with `only()` and `exclude()`. A call to a tool that was filtered out is reported to the model as an error, and nothing runs.

## Sub-features

- `toolkit-offer` offers seven tools in order, with parameter schemas.
- `toolkit-only` restricts the offered tools to the named classes.
- `toolkit-unregistered` returns `The tool <name> is not registered on this agent: the call cannot be executed.` for a filtered-out tool, without side effects.

## How to get to it (user POV)

- A consumer runs `$agent->addTool(new CodingToolkit($dir))`, optionally `->only([ReadTool::class, ...])` or `->exclude([...])`.
- The model sees the offered tools on its first request.

## Driving it with drive.php

Preconditions:

- `doctor.sh` is clean (it checks the full tool order).

- **Full offer.** Run any scenario without `--only`, e.g. `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/edit.json`. Its `offered-tools.json` lists the seven names in order, with each tool's parameters.
- **Filtered offer.** Run `php8.4 .claude/skills/verify-coding-toolkit/scripts/drive.php .claude/skills/verify-coding-toolkit/scenarios/toolkit.json --only=read,grep`. `summary.txt` shows `only: read,grep` and `RESULT: PASS`, and `offered-tools.json` lists only `read` and `grep`.
- **Unregistered call.** In the same run, call 2 (`write`) returns `ERROR: The tool write is not registered on this agent: the call cannot be executed.` and `workspace changes: (none)`, with `hello.txt` still `Hello toolkit\n`.

## Gotchas

- `toolkit.json` only passes with `--only=read,grep`. Without it, `write` runs and the scenario fails on purpose.
- `exclude()` has no drive flag yet. Verify it with a scenario plus a new flag, or rely on `tests/CodingToolkitTest.php`, and say which one you used.
- The unregistered-tool error comes from Neuron, not this library. If its wording changes after a `neuron-core/neuron-ai` upgrade, update the scenario, not the toolkit.
