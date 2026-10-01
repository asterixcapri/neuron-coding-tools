<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * File search: finds files under a directory and searches their contents,
 * respecting `.gitignore` when it can. The `find` and `grep` Tools delegate
 * to it; implementations are ripgrep and pure PHP, chosen once at startup by
 * {@see FileSearchSelector}.
 *
 * Both implementations agree on the glob dialect, ripgrep's: `*` and `?`
 * never cross a `/`, `**` does, `[...]` and `{a,b}` are supported, a pattern
 * without `/` matches file names at any depth and a pattern with `/` matches
 * the path relative to the searched directory.
 */
interface FileSearchInterface
{
    /**
     * The files under $directory whose path matches $pattern, as paths
     * relative to $directory with `/` separators, sorted, at most $limit of
     * them.
     *
     * @param string $directory an existing absolute directory
     * @param int    $limit     a positive count
     *
     * @throws FileSearchException when the pattern is not a valid glob or the
     *                             search cannot run
     */
    public function listFiles(string $directory, string $pattern, int $limit): FileListing;

    /**
     * The lines under $path matching $pattern, a regex in ripgrep's dialect
     * unless the options say it is literal. Files come in a deterministic
     * walk order: the entries of every directory sorted by name, so `src/b`
     * precedes `src-x`. The glob is matched the way `find` matches it, after
     * the ignore rules, so an ignored file never comes back.
     *
     * A single file given as $path is searched whatever the glob and its
     * matches carry the file's name as their path.
     *
     * @param string $path an existing absolute directory or file
     *
     * @throws FileSearchException when the pattern or the glob is invalid or
     *                             the search cannot run
     */
    public function searchContent(string $path, string $pattern, ContentSearchOptions $options): ContentSearchResult;
}
