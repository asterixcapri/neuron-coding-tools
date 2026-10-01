<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\BashTool;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function array_map;
use function file_get_contents;
use function filesize;
use function implode;
use function microtime;
use function preg_match;
use function range;
use function realpath;
use function shell_exec;
use function strlen;
use function trim;
use function unlink;

final class BashToolTest extends TestCase
{
    use TemporaryDirectory;

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new BashTool($this->directory);

        self::assertSame('bash', $tool->getName());
        self::assertStringStartsWith('Execute a bash command in the current working directory.', $tool->getDescription() ?? '');
        self::assertSame(['command', 'timeout'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame(['command'], $tool->getRequiredProperties());
    }

    public function testItRunsInTheWorkingDirectory(): void
    {
        self::assertSame(realpath($this->directory) . "\n", $this->bash('pwd'));
    }

    public function testStdoutAndStderrAppearInArrivalOrder(): void
    {
        $result = $this->bash('echo one; echo two >&2; sleep 0.05; echo three; sleep 0.05; echo four >&2');

        self::assertSame("one\ntwo\nthree\nfour\n", $result);
    }

    public function testAnEmptyOutputIsReported(): void
    {
        self::assertSame('(no output)', $this->bash('true'));
    }

    public function testANonZeroExitIsReportedWithTheOutput(): void
    {
        $tool = new BashTool($this->directory);
        $tool->setInputs(['command' => 'echo failing; exit 3']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("failing\n\n\nCommand exited with code 3");

        $tool->execute();
    }

    public function testAMissingWorkingDirectoryIsReported(): void
    {
        $tool = new BashTool($this->directory . '/missing');
        $tool->setInputs(['command' => 'true']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Working directory does not exist: {$this->directory}/missing\nCannot execute bash commands.");

        $tool->execute();
    }

    public function testLongOutputIsTruncatedToItsLastLinesAndSavedInFull(): void
    {
        // pi counts the empty line after the final newline, so 3000 numbers
        // are 3001 lines and the last 2000 of them start at 1002.
        $result = $this->bash('seq 1 3000');

        self::assertStringStartsWith("1002\n1003\n", $result);
        self::assertMatchesRegularExpression(
            '~\n3000\n\n\n\[Showing lines 1002-3001 of 3001\. Full output: (\S+solaro-bash-[0-9a-f]{16}\.log)\]$~',
            $result,
        );

        $tempFile = $this->tempFileNamedIn($result);

        self::assertFileExists($tempFile);
        self::assertSame(implode("\n", range(1, 3000)) . "\n", file_get_contents($tempFile));

        unlink($tempFile);
    }

    public function testLargeOutputIsTruncatedByBytes(): void
    {
        // 1000 lines of 100 bytes: under the line limit, over 50KB.
        $result = $this->bash('for i in $(seq 1 1000); do printf "%099d\n" "$i"; done');

        self::assertMatchesRegularExpression(
            '~\[Showing lines \d+-1001 of 1001 \(50\.0KB limit\)\. Full output: (\S+)\]$~',
            $result,
        );
        self::assertLessThanOrEqual(50 * 1024 + 200, strlen($result));

        $tempFile = $this->tempFileNamedIn($result);

        self::assertSame(100 * 1000, filesize($tempFile));

        unlink($tempFile);
    }

    public function testTheNoteCountsTheWholeOutputBeyondTheRollingBuffer(): void
    {
        // 30000 numbers are about 170KB, more than the 100KB the capture
        // keeps in memory; the note must still count every line.
        $result = $this->bash('seq 1 30000');

        self::assertStringStartsWith("28002\n28003\n", $result);
        self::assertMatchesRegularExpression('~\n30000\n\n\n\[Showing lines 28002-30001 of 30001\. Full output: (\S+)\]$~', $result);

        $tempFile = $this->tempFileNamedIn($result);

        self::assertSame(implode("\n", range(1, 30000)) . "\n", file_get_contents($tempFile));

        unlink($tempFile);
    }

    public function testASingleLineBeyondTheRollingBufferIsReportedWithItsTrueSize(): void
    {
        // One 150000-byte line: the shown tail is 50KB, the note its full size.
        $result = $this->bash('head -c 150000 /dev/zero | tr "\\0" x');

        self::assertMatchesRegularExpression('~^x{51200}\n\n\[Showing last 50\.0KB of line 1 \(line is 146\.5KB\)\. Full output: (\S+)\]$~', $result);

        $tempFile = $this->tempFileNamedIn($result);

        self::assertSame(150000, filesize($tempFile));

        unlink($tempFile);
    }

    public function testTimeoutKillsTheProcessAndItsChildrenAndIsReported(): void
    {
        $tool = new BashTool($this->directory);
        $tool->setInputs(['command' => 'echo started; sleep 31; echo late', 'timeout' => 1]);

        $started = microtime(true);

        try {
            $tool->execute();
            self::fail('The command should have timed out.');
        } catch (RuntimeException $exception) {
            self::assertSame("started\n\n\nCommand timed out after 1 seconds", $exception->getMessage());
        }

        self::assertLessThan(5, microtime(true) - $started);
        self::assertSame('', trim((string) shell_exec("pgrep -x -f 'sleep 31' || true")), 'the sleep is still running');
    }

    public function testWithoutTimeoutNothingIsKilled(): void
    {
        $started = microtime(true);

        self::assertSame("done\n", $this->bash('sleep 1.2; echo done'));
        self::assertGreaterThanOrEqual(1.2, microtime(true) - $started);
    }

    public function testTheEventLoopKeepsTickingWhileACommandRuns(): void
    {
        $ticks = 0;
        $timer = EventLoop::repeat(0.05, static function () use (&$ticks): void {
            $ticks++;
        });

        try {
            self::assertSame("awake\n", $this->bash('sleep 1; echo awake'));
        } finally {
            EventLoop::cancel($timer);
        }

        self::assertGreaterThan(10, $ticks);
    }

    private function tempFileNamedIn(string $result): string
    {
        if (preg_match('~Full output: (\S+)\]$~', $result, $matches) !== 1) {
            self::fail('The result names no temporary file.');
        }

        return $matches[1];
    }

    private function bash(string $command): string
    {
        $tool = new BashTool($this->directory);
        $tool->setInputs(['command' => $command]);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }
}
