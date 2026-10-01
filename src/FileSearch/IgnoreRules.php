<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use function file_get_contents;
use function is_file;
use function preg_match;
use function preg_split;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * The rules of a directory's root `.gitignore`, enough for the pure PHP
 * File search to agree with ripgrep on the common cases: blank lines and
 * comments skipped, `!` negation, a trailing `/` for directories only, a
 * pattern with a `/` anchored at the root and any other pattern matched
 * against the name at any depth. The last matching rule wins. Nested
 * `.gitignore` files are not read.
 */
final class IgnoreRules
{
    /**
     * @param list<IgnoreRule> $rules
     */
    private function __construct(
        private readonly array $rules,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $file = $directory . '/.gitignore';

        if (!is_file($file)) {
            return new self([]);
        }

        $content = file_get_contents($file);

        return self::parse($content === false ? '' : $content);
    }

    public static function parse(string $content): self
    {
        $rules = [];

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $negated = str_starts_with($line, '!');
            $pattern = $negated ? substr($line, 1) : $line;
            $directoryOnly = str_ends_with($pattern, '/');
            $pattern = rtrim($pattern, '/');

            if ($pattern === '') {
                continue;
            }

            try {
                $rules[] = new IgnoreRule(Glob::toRegex($pattern), $negated, $directoryOnly);
            } catch (FileSearchException) {
                // git skips a malformed line; so do we.
            }
        }

        return new self($rules);
    }

    /**
     * @param string $relativePath with `/` separators, no leading `/`
     */
    public function ignores(string $relativePath, bool $isDirectory): bool
    {
        $ignored = false;

        foreach ($this->rules as $rule) {
            if ($rule->directoryOnly && !$isDirectory) {
                continue;
            }

            if (preg_match($rule->regex, $relativePath) === 1) {
                $ignored = !$rule->negated;
            }
        }

        return $ignored;
    }
}
