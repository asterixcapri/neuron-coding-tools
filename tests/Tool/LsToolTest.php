<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\LsTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function file_put_contents;
use function mkdir;
use function touch;

final class LsToolTest extends TestCase
{
    use TemporaryDirectory;

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new LsTool($this->directory);

        self::assertSame('ls', $tool->getName());
        self::assertSame(
            "List directory contents. Returns entries sorted alphabetically, with '/' suffix for directories. Includes dotfiles. Output is truncated to 500 entries or 50KB (whichever is hit first).",
            $tool->getDescription(),
        );
        self::assertSame(['path', 'limit'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame([], $tool->getRequiredProperties());
    }

    public function testItSortsCaseInsensitivelyAndMarksDirectoriesWithASlash(): void
    {
        touch($this->directory . '/zeta.txt');
        touch($this->directory . '/Alpha.txt');
        mkdir($this->directory . '/beta');
        touch($this->directory . '/gamma.txt');
        mkdir($this->directory . '/Delta');

        self::assertSame("Alpha.txt\nbeta/\nDelta/\ngamma.txt\nzeta.txt", $this->ls(['path' => $this->directory]));
    }

    public function testItListsDotfilesAndDirectories(): void
    {
        file_put_contents($this->directory . '/.hidden-file', 'secret');
        mkdir($this->directory . '/.hidden-dir');

        $output = $this->ls(['path' => $this->directory]);

        self::assertStringContainsString('.hidden-file', $output);
        self::assertStringContainsString('.hidden-dir/', $output);
    }

    public function testTheDefaultPathIsTheWorkingDirectory(): void
    {
        touch($this->directory . '/only.txt');
        mkdir($this->directory . '/sub');

        self::assertSame("only.txt\nsub/", $this->ls([]));
    }

    public function testARelativePathIsResolvedAgainstTheWorkingDirectory(): void
    {
        mkdir($this->directory . '/sub');
        touch($this->directory . '/sub/inner.txt');

        self::assertSame('inner.txt', $this->ls(['path' => 'sub']));
    }

    public function testAnEmptyDirectoryIsReportedAsSuch(): void
    {
        self::assertSame('(empty directory)', $this->ls([]));
    }

    public function testTheLimitTruncatesTheListingWithPisNote(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            touch($this->directory . "/{$name}.txt");
        }

        self::assertSame(
            "a.txt\nb.txt\nc.txt\n\n[3 entries limit reached. Use limit=6 for more]",
            $this->ls(['limit' => 3]),
        );
    }

    public function testAListingWithinTheLimitHasNoNote(): void
    {
        touch($this->directory . '/a.txt');
        touch($this->directory . '/b.txt');

        self::assertSame("a.txt\nb.txt", $this->ls(['limit' => 2]));
    }

    public function testAMissingDirectoryIsReported(): void
    {
        $tool = new LsTool($this->directory);
        $tool->setInputs(['path' => 'nowhere']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Path not found: {$this->directory}/nowhere");

        $tool->execute();
    }

    public function testAFilePathIsReported(): void
    {
        touch($this->directory . '/file.txt');

        $tool = new LsTool($this->directory);
        $tool->setInputs(['path' => 'file.txt']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Not a directory: {$this->directory}/file.txt");

        $tool->execute();
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function ls(array $inputs): string
    {
        $tool = new LsTool($this->directory);
        $tool->setInputs($inputs);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }
}
