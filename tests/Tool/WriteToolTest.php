<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\WriteTool;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function putenv;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

final class WriteToolTest extends TestCase
{
    use TemporaryDirectory;

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new WriteTool($this->directory);

        self::assertSame('write', $tool->getName());
        self::assertStringStartsWith('Write content to a file.', $tool->getDescription() ?? '');
        self::assertSame(['path', 'content'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame(['path', 'content'], $tool->getRequiredProperties());
    }

    public function testItWritesARelativePathIntoTheWorkingDirectory(): void
    {
        $result = $this->write('write-test.txt', 'Test content');

        self::assertSame('Successfully wrote 12 bytes to write-test.txt', $result);
        self::assertSame('Test content', file_get_contents($this->directory . '/write-test.txt'));
    }

    public function testItCreatesParentDirectories(): void
    {
        $result = $this->write('nested/dir/test.txt', 'Nested content');

        self::assertSame('Successfully wrote 14 bytes to nested/dir/test.txt', $result);
        self::assertSame('Nested content', file_get_contents($this->directory . '/nested/dir/test.txt'));
    }

    public function testItOverwritesAnExistingFile(): void
    {
        file_put_contents($this->directory . '/existing.txt', "old content that is longer\n");

        $this->write('existing.txt', 'new');

        self::assertSame('new', file_get_contents($this->directory . '/existing.txt'));
    }

    public function testAnAbsolutePathIsWrittenAnywhere(): void
    {
        $elsewhere = sys_get_temp_dir() . '/solaro-write-elsewhere-' . bin2hex(random_bytes(4)) . '.txt';

        try {
            self::assertSame("Successfully wrote 8 bytes to {$elsewhere}", $this->write($elsewhere, 'far away'));
            self::assertSame('far away', file_get_contents($elsewhere));
        } finally {
            unlink($elsewhere);
        }
    }

    public function testATildePathExpandsToTheHomeDirectory(): void
    {
        $home = getenv('HOME');
        putenv("HOME={$this->directory}");

        try {
            $tool = new WriteTool('/nowhere');
            $tool->setInputs(['path' => '~/home.txt', 'content' => 'home sweet home']);
            $tool->execute();

            self::assertSame('Successfully wrote 15 bytes to ~/home.txt', $tool->getResult());
            self::assertSame('home sweet home', file_get_contents($this->directory . '/home.txt'));
        } finally {
            putenv($home === false ? 'HOME' : "HOME={$home}");
        }
    }

    private function write(string $path, string $content): string
    {
        $tool = new WriteTool($this->directory);
        $tool->setInputs(['path' => $path, 'content' => $content]);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }
}
