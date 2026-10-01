<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

use function array_pop;
use function explode;
use function getenv;
use function implode;
use function is_string;
use function posix_getpwuid;
use function posix_getuid;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function substr;

/**
 * Resolves the paths the model passes to a Tool, the way pi's path-utils does.
 *
 * A relative path is resolved against the working directory, `~` and `~/...`
 * expand to the home directory, and an absolute path is kept as it is. A
 * leading `@` (as in chat mentions) is dropped and exotic Unicode spaces are
 * turned into plain spaces. Nothing is checked against the file system.
 */
final class PathResolver
{
    private const string UNICODE_SPACES = '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    /**
     * The absolute path for a Tool argument.
     */
    public function resolve(string $path): string
    {
        $expanded = self::expand($path);

        if (str_starts_with($expanded, '/')) {
            return $expanded;
        }

        return self::normalize($this->workingDirectory . '/' . $expanded);
    }

    /**
     * pi's `expandPath`: strips a leading `@`, normalises Unicode spaces and
     * expands `~`. The result may still be relative.
     */
    public static function expand(string $path): string
    {
        if (str_starts_with($path, '@')) {
            $path = substr($path, 1);
        }

        $path = preg_replace(self::UNICODE_SPACES, ' ', $path) ?? $path;

        if ($path === '~') {
            return self::homeDirectory();
        }

        if (str_starts_with($path, '~/')) {
            return self::homeDirectory() . substr($path, 1);
        }

        return $path;
    }

    /**
     * Lexical normalisation of an absolute path: collapses `.`, `..` and
     * repeated separators, and drops a trailing separator, as Node's
     * `path.resolve` does.
     */
    private static function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private static function homeDirectory(): string
    {
        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        $account = posix_getpwuid(posix_getuid());

        return $account !== false ? $account['dir'] : '/';
    }
}
