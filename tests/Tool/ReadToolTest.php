<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\ReadTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function base64_decode;
use function bin2hex;
use function file_put_contents;
use function getenv;
use function implode;
use function mkdir;
use function putenv;
use function random_bytes;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

final class ReadToolTest extends TestCase
{
    use TemporaryDirectory;

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new ReadTool($this->directory);

        self::assertSame('read', $tool->getName());
        self::assertStringStartsWith('Read the contents of a file.', $tool->getDescription() ?? '');
        // Solaro departs from pi's wording here: there are no attachments yet.
        self::assertStringContainsString('Binary files, including images, are described but not returned.', $tool->getDescription() ?? '');
        self::assertSame(['path', 'offset', 'limit'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame(['path'], $tool->getRequiredProperties());
    }

    public function testItReadsAWholeFileThatFitsWithinTheLimits(): void
    {
        $content = "Hello, world!\nLine 2\nLine 3";
        file_put_contents($this->directory . '/test.txt', $content);

        $result = $this->read(['path' => 'test.txt']);

        self::assertSame($content, $result);
        self::assertStringNotContainsString('Use offset=', $result);
    }

    public function testAMissingFileIsReported(): void
    {
        $tool = new ReadTool($this->directory);
        $tool->setInputs(['path' => 'nonexistent.txt']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found: nonexistent.txt');

        $tool->execute();
    }

    public function testADirectoryIsReported(): void
    {
        mkdir($this->directory . '/sub');

        $tool = new ReadTool($this->directory);
        $tool->setInputs(['path' => 'sub']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path is a directory, not a file: sub');

        $tool->execute();
    }

    public function testAFileExceedingTheLineLimitIsTruncated(): void
    {
        $this->writeLines('large.txt', 2500);

        $result = $this->read(['path' => 'large.txt']);

        self::assertStringStartsWith("Line 1\n", $result);
        self::assertStringContainsString("\nLine 2000\n", $result);
        self::assertStringNotContainsString('Line 2001', $result);
        self::assertStringEndsWith('[Showing lines 1-2000 of 2500. Use offset=2001 to continue.]', $result);
    }

    public function testAFileExceedingTheByteLimitIsTruncated(): void
    {
        $lines = [];

        for ($i = 1; $i <= 500; $i++) {
            $lines[] = "Line {$i}: " . str_repeat('x', 200);
        }

        file_put_contents($this->directory . '/large-bytes.txt', implode("\n", $lines));

        $result = $this->read(['path' => 'large-bytes.txt']);

        self::assertStringStartsWith('Line 1:', $result);
        self::assertMatchesRegularExpression(
            '~\[Showing lines 1-(\d+) of 500 \(50\.0KB limit\)\. Use offset=(\d+) to continue\.\]$~',
            $result,
        );
        self::assertLessThanOrEqual(50 * 1024 + 100, strlen($result));
    }

    public function testOffsetStartsAtTheGivenLine(): void
    {
        $this->writeLines('offset-test.txt', 100);

        $result = $this->read(['path' => 'offset-test.txt', 'offset' => 51]);

        self::assertStringStartsWith("Line 51\n", $result);
        self::assertStringNotContainsString('Line 50', $result);
        self::assertStringEndsWith("\nLine 100", $result);
        self::assertStringNotContainsString('Use offset=', $result);
    }

    public function testLimitStopsEarlyAndReportsTheRest(): void
    {
        $this->writeLines('limit-test.txt', 100);

        $result = $this->read(['path' => 'limit-test.txt', 'limit' => 10]);

        self::assertStringStartsWith("Line 1\n", $result);
        self::assertStringContainsString("\nLine 10\n", $result);
        self::assertStringNotContainsString('Line 11', $result);
        self::assertStringEndsWith('[90 more lines in file. Use offset=11 to continue.]', $result);
    }

    public function testOffsetAndLimitSelectAPage(): void
    {
        $this->writeLines('offset-limit-test.txt', 100);

        $result = $this->read(['path' => 'offset-limit-test.txt', 'offset' => 41, 'limit' => 20]);

        self::assertStringStartsWith("Line 41\n", $result);
        self::assertStringNotContainsString('Line 40', $result);
        self::assertStringContainsString("\nLine 60\n", $result);
        self::assertStringNotContainsString('Line 61', $result);
        self::assertStringEndsWith('[40 more lines in file. Use offset=61 to continue.]', $result);
    }

    public function testAnOffsetBeyondTheEndIsReported(): void
    {
        file_put_contents($this->directory . '/short.txt', "Line 1\nLine 2\nLine 3");

        $tool = new ReadTool($this->directory);
        $tool->setInputs(['path' => 'short.txt', 'offset' => 100]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Offset 100 is beyond end of file (3 lines total)');

        $tool->execute();
    }

    public function testAnImageIsDescribedNotDumped(): void
    {
        // A 1x1 PNG, as in pi's test; stored under a .txt name to prove the
        // kind comes from the content, not the extension.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg==', true);
        self::assertNotFalse($png);
        file_put_contents($this->directory . '/image.txt', $png);

        self::assertSame(
            'Binary file (image/png, ' . strlen($png) . 'B); content not shown.',
            $this->read(['path' => 'image.txt']),
        );
    }

    public function testAnyOtherBinaryFileIsDescribedWithItsSize(): void
    {
        file_put_contents($this->directory . '/blob.bin', str_repeat("\x00\x01\x02\xFF", 1024));

        self::assertSame(
            'Binary file (application/octet-stream, 4.0KB); content not shown.',
            $this->read(['path' => 'blob.bin']),
        );
    }

    public function testAFileWithAnImageExtensionButTextContentIsReadAsText(): void
    {
        file_put_contents($this->directory . '/not-an-image.png', 'definitely not a png');

        self::assertSame('definitely not a png', $this->read(['path' => 'not-an-image.png']));
    }

    public function testAnAbsolutePathIsReadAnywhere(): void
    {
        $elsewhere = sys_get_temp_dir() . '/solaro-read-elsewhere-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($elsewhere, 'far away');

        try {
            self::assertSame('far away', $this->read(['path' => $elsewhere]));
        } finally {
            unlink($elsewhere);
        }
    }

    public function testATildePathExpandsToTheHomeDirectory(): void
    {
        file_put_contents($this->directory . '/home.txt', 'home sweet home');
        $home = getenv('HOME');
        putenv("HOME={$this->directory}");

        try {
            $tool = new ReadTool('/nowhere');
            $tool->setInputs(['path' => '~/home.txt']);
            $tool->execute();

            self::assertSame('home sweet home', $tool->getResult());
        } finally {
            putenv($home === false ? 'HOME' : "HOME={$home}");
        }
    }

    private function writeLines(string $name, int $count): void
    {
        $lines = [];

        for ($i = 1; $i <= $count; $i++) {
            $lines[] = "Line {$i}";
        }

        file_put_contents($this->directory . '/' . $name, implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function read(array $inputs): string
    {
        $tool = new ReadTool($this->directory);
        $tool->setInputs($inputs);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }
}
