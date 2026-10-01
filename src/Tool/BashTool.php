<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\Support\Executable;
use NeuronCodingTools\Tool\Bash\OutputCapture;
use NeuronCodingTools\Tool\Support\TruncatedBy;
use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function Amp\async;
use function escapeshellarg;
use function floor;
use function is_dir;
use function is_file;
use function is_float;
use function posix_kill;

use const SIGKILL;

/**
 * pi's `bash` tool: runs a command in the working directory and returns
 * stdout and stderr together, in arrival order.
 *
 * The process runs through Amp so the terminal keeps animating while a build
 * or a test suite is in progress. There is no default timeout; when the model
 * asks for one, the whole process group is killed once it expires. A failing
 * command, a timeout and a missing working directory are reported by
 * throwing: the Agent's tool error handler turns the message into the result.
 */
final class BashTool extends Tool
{
    protected string $name = 'bash';

    protected ?string $description = 'Execute a bash command in the current working directory. Returns stdout and stderr. Output is truncated to last 2000 lines or 50KB (whichever is hit first). If truncated, full output is saved to a temp file. Optionally provide a timeout in seconds.';

    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'command',
                type: PropertyType::STRING,
                description: 'Bash command to execute',
                required: true,
            ),
            ToolProperty::make(
                name: 'timeout',
                type: PropertyType::NUMBER,
                description: 'Timeout in seconds (optional, no default timeout)',
                required: false,
            ),
        ];
    }

    public function __invoke(string $command, int|float|null $timeout = null): string
    {
        if (!is_dir($this->workingDirectory)) {
            throw new RuntimeException(
                "Working directory does not exist: {$this->workingDirectory}\nCannot execute bash commands.",
            );
        }

        $capture = new OutputCapture();
        $process = Process::start(self::shellCommand($command), $this->workingDirectory);
        $process->getStdin()->close();

        $readers = [
            async(static fn() => self::drain($process->getStdout(), $capture)),
            async(static fn() => self::drain($process->getStderr(), $capture)),
        ];

        try {
            $exitCode = $process->join($timeout !== null && $timeout > 0 ? new TimeoutCancellation((float) $timeout) : null);
        } catch (CancelledException) {
            self::killTree($process);
            $process->join();

            foreach ($readers as $reader) {
                $reader->await();
            }

            $capture->close();
            $output = $capture->text();

            throw new RuntimeException(
                ($output !== '' ? $output . "\n\n" : '') . 'Command timed out after ' . self::seconds($timeout ?? 0) . ' seconds',
            );
        }

        foreach ($readers as $reader) {
            $reader->await();
        }

        $truncation = Truncation::tail($capture->text());
        $tempFilePath = $truncation->truncated ? $capture->ensureTempFile() : null;
        $capture->close();

        $outputText = $truncation->content !== '' ? $truncation->content : '(no output)';

        if ($truncation->truncated) {
            // The totals come from the capture, not from the truncation: the
            // buffer only holds the tail of a long output, the file all of it.
            $totalLines = $capture->totalLines();
            $startLine = $totalLines - $truncation->outputLines + 1;
            $endLine = $totalLines;

            if ($truncation->lastLinePartial) {
                $lastLineSize = Truncation::formatSize($capture->lastLineBytes());
                $outputText .= "\n\n[Showing last " . Truncation::formatSize($truncation->outputBytes) . " of line {$endLine} (line is {$lastLineSize}). Full output: {$tempFilePath}]";
            } elseif ($truncation->truncatedBy === TruncatedBy::Lines) {
                $outputText .= "\n\n[Showing lines {$startLine}-{$endLine} of {$totalLines}. Full output: {$tempFilePath}]";
            } else {
                $outputText .= "\n\n[Showing lines {$startLine}-{$endLine} of {$totalLines} (" . Truncation::formatSize(Truncation::DEFAULT_MAX_BYTES) . " limit). Full output: {$tempFilePath}]";
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException($outputText . "\n\nCommand exited with code {$exitCode}");
        }

        return $outputText;
    }

    /**
     * The shell line Amp runs: the command under `bash -c` in a session of
     * its own, so a timeout can kill the whole tree via the process group.
     */
    private static function shellCommand(string $command): string
    {
        $shell = is_file('/bin/bash') ? '/bin/bash' : (Executable::onPath('bash') ?? 'sh');
        $setsid = Executable::onPath('setsid');

        $line = escapeshellarg($shell) . ' -c ' . escapeshellarg($command);

        return $setsid === null ? 'exec ' . $line : 'exec ' . escapeshellarg($setsid) . ' ' . $line;
    }

    private static function drain(ReadableStream $stream, OutputCapture $capture): void
    {
        while (($chunk = $stream->read()) !== null) {
            $capture->append($chunk);
        }
    }

    private static function killTree(Process $process): void
    {
        if (!$process->isRunning()) {
            return;
        }

        if (!posix_kill(-$process->getPid(), SIGKILL)) {
            $process->kill();
        }
    }

    /**
     * The timeout as the model gave it: `5` for 5 and 5.0, `1.5` for 1.5.
     */
    private static function seconds(int|float $timeout): string
    {
        return (string) (is_float($timeout) && floor($timeout) === $timeout ? (int) $timeout : $timeout);
    }
}
