<?php

declare(strict_types=1);

/*
 * Drives the coding toolkit the way a consumer does: a real Neuron Agent with
 * the CodingToolkit registered, where only the LLM is faked (FakeAIProvider
 * replays the scenario's tool calls, one model turn per call). Everything
 * runs inside a fresh sandbox under the system temp directory; evidence is
 * written to .verify/evidence/<scenario>-<timestamp>/ and survives cleanup.
 *
 * Usage: php8.4 drive.php <scenario.json> [--search=auto|php|rg] [--only=read,grep] [--keep]
 * Exit:  0 all expectations held, 1 an expectation failed, 2 usage/setup error.
 */

$repo = \dirname(__DIR__, 4);
$args = \array_slice($argv, 1);
$scenarioPath = null;
$search = 'auto';
$keep = false;
$only = [];

foreach ($args as $arg) {
    if (\str_starts_with($arg, '--search=')) {
        $search = \substr($arg, 9);
    } elseif (\str_starts_with($arg, '--only=')) {
        $only = \array_filter(\explode(',', \substr($arg, 7)));
    } elseif ($arg === '--keep') {
        $keep = true;
    } elseif ($scenarioPath === null) {
        $scenarioPath = $arg;
    } else {
        \fwrite(\STDERR, "Unexpected argument: {$arg}\n");
        exit(2);
    }
}

if ($scenarioPath === null || !\is_file($scenarioPath) || !\in_array($search, ['auto', 'php', 'rg'], true)) {
    \fwrite(\STDERR, "Usage: php8.4 drive.php <scenario.json> [--search=auto|php|rg] [--only=read,grep] [--keep]\n");
    exit(2);
}

$scenario = \json_decode((string) \file_get_contents($scenarioPath), true, flags: \JSON_THROW_ON_ERROR);
$name = \basename($scenarioPath, '.json');

// The sandbox: workspace/ is the toolkit's working directory, tmp/ becomes
// TMPDIR so bash overflow logs land where this run can see and remove them.
// PHP caches the temp dir on first use, so TMPDIR must be set before any
// sys_get_temp_dir() call, this script's included.
$sandbox = \rtrim(\getenv('TMPDIR') ?: '/tmp', '/') . '/ncto-verify-' . \bin2hex(\random_bytes(4));
$workspace = $sandbox . '/workspace';
$tmp = $sandbox . '/tmp';
\mkdir($workspace, 0o777, true);
\mkdir($tmp, 0o777, true);
\putenv("TMPDIR={$tmp}");
$_ENV['TMPDIR'] = $tmp;

// Register the sandbox so cleanup.sh removes exactly what this harness made.
$registry = $repo . '/.verify/sandboxes';
@\mkdir($registry, 0o777, true);
\file_put_contents($registry . '/' . \basename($sandbox), $sandbox . "\n");

require $repo . '/vendor/autoload.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronCodingTools\CodingToolkit;
use NeuronCodingTools\FileSearch\PhpFileSearch;
use NeuronCodingTools\FileSearch\RipgrepFileSearch;

foreach ($scenario['setup']['files'] ?? [] as $path => $content) {
    $target = $workspace . '/' . $path;
    @\mkdir(\dirname($target), 0o777, true);
    \file_put_contents($target, $content);
}

/** @return array<string, array{size: int, sha1: string, content?: string}> */
function snapshot(string $root): array
{
    $files = [];
    if (!\is_dir($root)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = \substr($file->getPathname(), \strlen($root) + 1);
        $bytes = (string) \file_get_contents($file->getPathname());
        $entry = ['size' => \strlen($bytes), 'sha1' => \sha1($bytes)];
        if (\strlen($bytes) <= 8192 && \mb_check_encoding($bytes, 'UTF-8')) {
            $entry['content'] = $bytes;
        }
        $files[$relative] = $entry;
    }
    \ksort($files);

    return $files;
}

$before = snapshot($workspace);

$fileSearch = match ($search) {
    'php' => new PhpFileSearch(),
    'rg' => new RipgrepFileSearch(),
    'auto' => null,
};

$calls = [];
$responses = [];
foreach ($scenario['calls'] as $index => $call) {
    $toolCall = new ToolCall(name: $call['name'], callId: "call-{$index}", inputs: $call['inputs'] ?? []);
    $calls[] = $toolCall;
    $responses[] = new ToolCallMessage(tools: [$toolCall]);
}
$responses[] = new AssistantMessage('Scenario complete.');

$provider = new FakeAIProvider(...$responses);
$agent = new Agent();
$toolkit = new CodingToolkit($workspace, $fileSearch);
if ($only !== []) {
    // The consumer-facing filter: only() takes tool classes.
    $toolkit->only(\array_map(static fn(string $tool): string => 'NeuronCodingTools\\Tool\\' . \ucfirst($tool) . 'Tool', $only));
}
$agent->addTool($toolkit);
$agent->setThreadId('verify-' . $name)->setAiProvider($provider);
// The handler the README recommends: failures go back to the model as text.
$agent->toolErrorHandler(static fn(Throwable $e): string => 'ERROR: ' . $e->getMessage());

$started = \microtime(true);
try {
    $agent->chat(new UserMessage($scenario['prompt'] ?? "Run scenario {$name}"))->getMessage();
    $agentError = null;
} catch (Throwable $e) {
    // An exception the error handler does not see (e.g. an unknown tool)
    // ends the run; it is recorded, and the scenario decides if it is expected.
    $agentError = $e::class . ': ' . $e->getMessage();
}
$elapsed = \microtime(true) - $started;

$after = snapshot($workspace);
$tmpFiles = snapshot($tmp);

// Evidence: what the model was offered, what each call returned, what the
// workspace looked like before and after, and any temp files bash wrote.
$evidence = $repo . '/.verify/evidence/' . $name . '-' . $search . '-' . \date('Ymd-His') . '-' . \substr(\basename($sandbox), -4);
\mkdir($evidence, 0o777, true);

$failures = [];
$expectedAgentError = $scenario['expect']['agentError'] ?? null;
if ($agentError !== null && ($expectedAgentError === null || !\str_contains($agentError, $expectedAgentError))) {
    $failures[] = "agent: run aborted with {$agentError}";
} elseif ($agentError === null && $expectedAgentError !== null) {
    $failures[] = "agent: expected the run to abort with {$expectedAgentError}";
}
$transcript = [];
foreach ($scenario['calls'] as $index => $call) {
    $result = $calls[$index]->hasResult() ? $calls[$index]->getResult() : null;
    $text = $result instanceof ToolOutput ? \json_encode($result->jsonSerialize()) : (string) $result;
    $expect = $call['expect'] ?? [];
    $isError = \str_starts_with($text, 'ERROR: ');

    if ($result === null) {
        if (!($expect['noResult'] ?? false)) {
            $failures[] = "call {$index} ({$call['name']}): no result (the call never ran)";
        }
        $transcript[] = ['name' => $call['name'], 'inputs' => $call['inputs'] ?? [], 'result' => null];
        continue;
    }

    if (($expect['error'] ?? false) !== $isError) {
        $failures[] = "call {$index} ({$call['name']}): expected " . (($expect['error'] ?? false) ? 'an error' : 'success') . ", got: {$text}";
    }
    if (isset($expect['equals']) && $text !== $expect['equals']) {
        $failures[] = "call {$index} ({$call['name']}): result differs from expect.equals";
    }
    foreach ((array) ($expect['contains'] ?? []) as $needle) {
        if (!\str_contains($text, $needle)) {
            $failures[] = "call {$index} ({$call['name']}): result lacks " . \json_encode($needle);
        }
    }
    foreach ((array) ($expect['matches'] ?? []) as $regex) {
        if (\preg_match($regex, $text) !== 1) {
            $failures[] = "call {$index} ({$call['name']}): result does not match {$regex}";
        }
    }
    $transcript[] = ['name' => $call['name'], 'inputs' => $call['inputs'] ?? [], 'result' => $text];
}

foreach ($scenario['expect']['files'] ?? [] as $path => $content) {
    if (!isset($after[$path])) {
        $failures[] = "workspace: {$path} does not exist";
    } elseif (\sha1($content) !== $after[$path]['sha1']) {
        $failures[] = "workspace: {$path} content differs from expect.files";
    }
}
foreach ($scenario['expect']['absent'] ?? [] as $path) {
    if (isset($after[$path])) {
        $failures[] = "workspace: {$path} should not exist";
    }
}
if (isset($scenario['expect']['tmpFiles']) && \count($tmpFiles) !== $scenario['expect']['tmpFiles']) {
    $failures[] = 'tmp: expected ' . $scenario['expect']['tmpFiles'] . ' temp file(s), found ' . \count($tmpFiles);
}

$offered = \array_map(
    static fn($tool) => ['name' => $tool->getName(), 'parameters' => $tool->jsonSerialize()['parameters']],
    $provider->getRecorded()[0]->tools,
);

$json = static fn(mixed $value): string => \json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
\file_put_contents($evidence . '/scenario.json', $json($scenario));
\file_put_contents($evidence . '/transcript.json', $json($transcript));
\file_put_contents($evidence . '/offered-tools.json', $json($offered));
\file_put_contents($evidence . '/workspace-before.json', $json($before));
\file_put_contents($evidence . '/workspace-after.json', $json($after));
\file_put_contents($evidence . '/tmp-files.json', $json($tmpFiles));

$changes = [];
foreach (\array_unique([...\array_keys($before), ...\array_keys($after)]) as $path) {
    $changes[] = match (true) {
        !isset($before[$path]) => "+ {$path}",
        !isset($after[$path]) => "- {$path}",
        $before[$path]['sha1'] !== $after[$path]['sha1'] => "~ {$path}",
        default => null,
    };
}
$changes = \array_values(\array_filter($changes));

$summary = "scenario: {$name}\nsearch: {$search}\nonly: " . ($only === [] ? '(all tools)' : \implode(',', $only)) . "\nphp: " . \PHP_VERSION . "\nmodel turns: " . $provider->getCallCount()
    . \sprintf("\nelapsed: %.2fs\n", $elapsed) . 'agent error: ' . ($agentError ?? '(none)') . "\n" . "\nworkspace changes:\n" . ($changes === [] ? "  (none)\n" : '  ' . \implode("\n  ", $changes) . "\n")
    . 'temp files written: ' . \count($tmpFiles) . "\n\n";
foreach ($transcript as $index => $entry) {
    $summary .= "--- call {$index}: {$entry['name']} " . \json_encode($entry['inputs'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n" . ($entry['result'] ?? '(no result: the call never ran)') . "\n\n";
}
$summary .= $failures === [] ? "RESULT: PASS\n" : "RESULT: FAIL\n  " . \implode("\n  ", $failures) . "\n";
\file_put_contents($evidence . '/summary.txt', $summary);

if ($keep) {
    \fwrite(\STDERR, "sandbox kept: {$sandbox}\n");
} else {
    \exec('rm -rf ' . \escapeshellarg($sandbox));
    @\unlink($registry . '/' . \basename($sandbox));
}

echo $summary, "evidence: {$evidence}\n";
exit($failures === [] ? 0 : 1);
