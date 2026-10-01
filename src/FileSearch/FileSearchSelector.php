<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use NeuronCodingTools\Support\Executable;

/**
 * Picks the File search implementation once at startup: ripgrep when `rg`
 * is on the `PATH`, pure PHP otherwise. Nothing is downloaded and nothing
 * fails when `rg` is absent.
 */
final class FileSearchSelector
{
    /**
     * @param string|null $searchPath the `PATH` to scan; the process environment's when null
     */
    public static function select(?string $searchPath = null): FileSearchInterface
    {
        $ripgrep = self::ripgrepOnPath($searchPath);

        return $ripgrep === null ? new PhpFileSearch() : new RipgrepFileSearch($ripgrep);
    }

    /**
     * The absolute path of the `rg` executable, or null when there is none.
     *
     * @param string|null $searchPath the `PATH` to scan; the process environment's when null
     */
    public static function ripgrepOnPath(?string $searchPath = null): ?string
    {
        return Executable::onPath('rg', $searchPath);
    }
}
