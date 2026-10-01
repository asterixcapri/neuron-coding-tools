<?php

declare(strict_types=1);

namespace NeuronCodingTools\Support;

use function explode;
use function getenv;
use function is_executable;
use function is_file;
use function is_string;

use const PATH_SEPARATOR;

/**
 * Finds a program on the `PATH`, the way a shell does: the first directory
 * holding an executable regular file of that name wins.
 */
final class Executable
{
    /**
     * @param string|null $searchPath the `PATH` to scan; the process environment's when null
     *
     * @return string|null the absolute path of the program, or null when there is none
     */
    public static function onPath(string $name, ?string $searchPath = null): ?string
    {
        if ($searchPath === null) {
            $environmentPath = getenv('PATH');
            $searchPath = is_string($environmentPath) ? $environmentPath : '';
        }

        foreach (explode(PATH_SEPARATOR, $searchPath) as $directory) {
            $candidate = $directory . '/' . $name;

            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
