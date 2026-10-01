<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\FileSearch;

use NeuronCodingTools\FileSearch\FileSearchSelector;
use NeuronCodingTools\FileSearch\PhpFileSearch;
use NeuronCodingTools\FileSearch\RipgrepFileSearch;
use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function mkdir;

use const PATH_SEPARATOR;

final class FileSearchSelectorTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        mkdir($this->directory . '/bin');
    }

    public function testRipgrepIsChosenWhenRgIsOnThePath(): void
    {
        file_put_contents($this->directory . '/bin/rg', "#!/bin/sh\n");
        chmod($this->directory . '/bin/rg', 0o755);

        $searchPath = $this->directory . PATH_SEPARATOR . $this->directory . '/bin';

        self::assertSame($this->directory . '/bin/rg', FileSearchSelector::ripgrepOnPath($searchPath));
        self::assertInstanceOf(RipgrepFileSearch::class, FileSearchSelector::select($searchPath));
    }

    public function testPhpIsChosenWhenRgIsMissingOrNotExecutable(): void
    {
        file_put_contents($this->directory . '/bin/rg', "#!/bin/sh\n");
        chmod($this->directory . '/bin/rg', 0o644);

        self::assertNull(FileSearchSelector::ripgrepOnPath($this->directory . '/bin'));
        self::assertInstanceOf(PhpFileSearch::class, FileSearchSelector::select($this->directory . '/bin'));
        self::assertInstanceOf(PhpFileSearch::class, FileSearchSelector::select(''));
    }
}
