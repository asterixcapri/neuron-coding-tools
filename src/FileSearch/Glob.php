<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use function array_map;
use function explode;
use function implode;
use function ltrim;
use function preg_quote;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * The glob dialect shared by both File search implementations: ripgrep's.
 *
 * `*` and `?` never match a `/`, `**` matches any number of path components,
 * `[...]` is a character class (`[!...]` negated) and `{a,b}` an alternation;
 * a backslash escapes the next character. A pattern that contains a `/` is
 * matched against the whole relative path, anchored at the searched
 * directory; any other pattern is matched against the file name at any depth.
 */
final class Glob
{
    /**
     * pi's rule for the `find` Tool: a pattern with a `/` that is not anchored
     * gets a `**\/` prefix so `src/**\/*.spec.ts` finds `lib/src/x.spec.ts` as
     * well. Patterns without a `/` are left alone; ripgrep already matches
     * them against file names at any depth.
     */
    public static function normalize(string $pattern): string
    {
        if (!str_contains($pattern, '/')) {
            return $pattern;
        }

        if (str_starts_with($pattern, '/') || str_starts_with($pattern, '**/') || $pattern === '**') {
            return $pattern;
        }

        return '**/' . $pattern;
    }

    /**
     * A PCRE pattern matching the relative paths the glob selects.
     *
     * @throws FileSearchException when the glob is malformed, with ripgrep's wording
     */
    public static function toRegex(string $pattern): string
    {
        $body = self::translate($pattern, $pattern);

        if (str_contains($pattern, '/')) {
            return '~\A' . $body . '\z~';
        }

        return '~(?:\A|/)' . $body . '\z~';
    }

    private static function translate(string $glob, string $whole): string
    {
        $glob = ltrim($glob, '/');
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if (substr($glob, $i, 2) === '**') {
                    $atStart = $i === 0 || $glob[$i - 1] === '/';
                    $followedBySlash = ($glob[$i + 2] ?? '') === '/';
                    $atEnd = $i + 2 === $length;

                    if ($atStart && $followedBySlash) {
                        $regex .= '(?:.*/)?';
                        $i += 2;
                        continue;
                    }

                    if ($atStart && $atEnd) {
                        $regex .= '.*';
                        $i++;
                        continue;
                    }
                }

                $regex .= '[^/]*';
                continue;
            }

            if ($char === '?') {
                $regex .= '[^/]';
                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($glob[++$i], '~');
                continue;
            }

            if ($char === '[') {
                // The first `]` right after the opening bracket is a literal.
                $end = $i + 2 <= $length ? strpos($glob, ']', $i + 2) : false;

                if ($end === false) {
                    throw new FileSearchException("error parsing glob '{$whole}': unclosed character class; missing ']'");
                }

                $class = substr($glob, $i + 1, $end - $i - 1);
                $negated = str_starts_with($class, '!');
                $class = str_replace(['\\', '^', '[', ']'], ['\\\\', '\\^', '\\[', '\\]'], $negated ? substr($class, 1) : $class);
                $regex .= '[' . ($negated ? '^' : '') . $class . ']';
                $i = $end;
                continue;
            }

            if ($char === '{') {
                $end = strpos($glob, '}', $i + 1);

                if ($end === false) {
                    throw new FileSearchException("error parsing glob '{$whole}': unclosed alternate group; missing '}'");
                }

                $alternatives = explode(',', substr($glob, $i + 1, $end - $i - 1));
                $regex .= '(?:' . implode('|', array_map(static fn(string $alternative): string => self::translate($alternative, $whole), $alternatives)) . ')';
                $i = $end;
                continue;
            }

            $regex .= preg_quote($char, '~');
        }

        return $regex;
    }
}
