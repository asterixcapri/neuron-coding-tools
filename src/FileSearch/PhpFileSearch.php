<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function basename;
use function count;
use function explode;
use function file_get_contents;
use function in_array;
use function is_dir;
use function max;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strcmp;
use function strlen;
use function substr;
use function trim;
use function usort;

/**
 * File search without ripgrep: walks the directory tree in PHP.
 *
 * `.git`, `vendor` and `node_modules` are skipped at any depth, as are
 * symbolic links; hidden files are included; the root `.gitignore` is
 * honoured through {@see IgnoreRules}. Content search reads every file that
 * survives the walk, skips the binary ones and matches each line with PCRE,
 * which agrees with ripgrep's regex dialect on everything a model is likely
 * to write.
 */
final class PhpFileSearch implements FileSearchInterface
{
    private const array EXCLUDED_DIRECTORIES = ['.git', 'vendor', 'node_modules'];

    /** How many leading bytes are inspected to decide whether a file is binary. */
    private const int SNIFF_BYTES = 8192;

    public function listFiles(string $directory, string $pattern, int $limit): FileListing
    {
        $regex = Glob::toRegex(Glob::normalize($pattern));
        $root = rtrim($directory, '/');
        $ignore = IgnoreRules::fromDirectory($root);
        $paths = [];

        foreach (self::walk($root, $ignore) as $relativePath) {
            if (preg_match($regex, $relativePath) === 1) {
                $paths[] = $relativePath;
            }
        }

        return FileListing::fromPaths($paths, $limit);
    }

    public function searchContent(string $path, string $pattern, ContentSearchOptions $options): ContentSearchResult
    {
        $linePattern = self::compile($pattern, $options);
        $walking = is_dir($path);
        $matches = [];
        $limitReached = false;

        foreach (self::filesToSearch($path, $options->glob) as $relativePath => $absolutePath) {
            $content = file_get_contents($absolutePath);

            // A binary file met on the walk is skipped, as ripgrep skips it;
            // a file named explicitly is searched whatever it holds.
            if ($content === false || ($walking && str_contains(substr($content, 0, self::SNIFF_BYTES), "\0"))) {
                continue;
            }

            $lines = self::splitLines($content);

            foreach ($lines as $index => $line) {
                if (!$linePattern->matches($line)) {
                    continue;
                }

                $lineNumber = $index + 1;
                $matches[] = new ContentMatch($relativePath, $lineNumber, $line, self::window($lines, $lineNumber, $options->context));

                if (count($matches) >= $options->limit) {
                    $limitReached = true;
                    break 2;
                }
            }
        }

        return new ContentSearchResult($matches, $limitReached);
    }

    /**
     * The files a content search reads, relative path => absolute path, in
     * ripgrep's `--sort path` order: the entries of every directory by name.
     * A single file is searched whatever the glob says, as ripgrep does.
     *
     * @return iterable<string, string>
     */
    private static function filesToSearch(string $path, ?string $glob): iterable
    {
        if (!is_dir($path)) {
            yield basename($path) => $path;

            return;
        }

        $root = rtrim($path, '/');
        $regex = $glob === null ? null : Glob::toRegex($glob);
        $paths = [];

        foreach (self::walk($root, IgnoreRules::fromDirectory($root)) as $relativePath) {
            if ($regex === null || preg_match($regex, $relativePath) === 1) {
                $paths[] = $relativePath;
            }
        }

        usort($paths, self::comparePaths(...));

        foreach ($paths as $relativePath) {
            yield $relativePath => $root . '/' . $relativePath;
        }
    }

    /**
     * Component by component, so a directory sorts among the files of its
     * parent by its own name, as ripgrep walks.
     */
    private static function comparePaths(string $a, string $b): int
    {
        $left = explode('/', $a);
        $right = explode('/', $b);
        $depth = min(count($left), count($right));

        for ($i = 0; $i < $depth; $i++) {
            $order = strcmp($left[$i], $right[$i]);

            if ($order !== 0) {
                return $order;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * pi's window around a match: every existing line within $context of it,
     * the match line itself excluded.
     *
     * @param list<string> $lines
     *
     * @return array<int, string>
     */
    private static function window(array $lines, int $lineNumber, int $context): array
    {
        if ($context <= 0) {
            return [];
        }

        $window = [];
        $last = min(count($lines), $lineNumber + $context);

        for ($current = max(1, $lineNumber - $context); $current <= $last; $current++) {
            if ($current !== $lineNumber) {
                $window[$current] = $lines[$current - 1];
            }
        }

        return $window;
    }

    /**
     * The lines of a file as ripgrep counts them: split on LF, `\r` dropped,
     * and no phantom empty line after a final newline.
     *
     * @return list<string>
     */
    private static function splitLines(string $content): array
    {
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        return explode("\n", str_replace("\r", '', $content));
    }

    /**
     * The search pattern compiled to PCRE.
     *
     * @throws FileSearchException when the regex does not compile, with PCRE's wording
     */
    private static function compile(string $pattern, ContentSearchOptions $options): LinePattern
    {
        $body = $options->literal ? preg_quote($pattern, '~') : self::escapeDelimiter($pattern);
        $modifiers = $options->ignoreCase ? 'i' : '';
        $byteRegex = '~' . $body . '~' . $modifiers;

        $warning = null;
        set_error_handler(static function (int $number, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $compiles = preg_match($byteRegex, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$compiles) {
            $detail = $warning === null ? '' : ': ' . trim((string) preg_replace('/^preg_match\(\): /', '', $warning));

            throw new FileSearchException("regex parse error{$detail}");
        }

        return new LinePattern('~' . $body . '~u' . $modifiers, $byteRegex);
    }

    /**
     * Escapes the `~` delimiter inside a user regex without touching what is
     * already escaped, so `\~` stays `\~`.
     */
    private static function escapeDelimiter(string $pattern): string
    {
        $escaped = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $escaped .= $char . $pattern[++$i];
                continue;
            }

            $escaped .= $char === '~' ? '\~' : $char;
        }

        return $escaped;
    }

    /**
     * The relative paths of all files that are not excluded or ignored.
     *
     * @return Generator<int, string>
     */
    private static function walk(string $root, IgnoreRules $ignore): Generator
    {
        $prefixLength = strlen($root) + 1;

        $filter = static function (SplFileInfo $entry) use ($prefixLength, $ignore): bool {
            if ($entry->isLink()) {
                return false;
            }

            $relativePath = substr($entry->getPathname(), $prefixLength);

            if ($entry->isDir() && in_array($entry->getFilename(), self::EXCLUDED_DIRECTORIES, true)) {
                return false;
            }

            return !$ignore->ignores($relativePath, $entry->isDir());
        };

        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                $filter,
            ),
        );

        // The iterator stack loses the element type for PHPStan, so the check
        // stays: it is what lets `$file` be an SplFileInfo here.
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield substr($file->getPathname(), $prefixLength);
            }
        }
    }
}
