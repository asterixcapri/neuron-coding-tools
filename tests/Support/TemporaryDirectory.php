<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

use function bin2hex;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

/**
 * A fresh, empty directory under the system temporary directory for every
 * test, in `$this->directory`, removed with everything in it afterwards.
 *
 * The directory exists before `setUp()` runs, so a test class can populate
 * it there.
 */
trait TemporaryDirectory
{
    private string $directory;

    #[Before]
    protected function createTemporaryDirectory(): void
    {
        $this->directory = sys_get_temp_dir() . '/solaro-test-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        self::removeTree($this->directory);
    }

    private static function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
