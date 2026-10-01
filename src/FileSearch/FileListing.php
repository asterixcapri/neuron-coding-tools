<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use function array_slice;
use function count;
use function sort;

use const SORT_STRING;

/**
 * The outcome of {@see FileSearchInterface::listFiles()}.
 */
final readonly class FileListing
{
    /**
     * @param list<string> $paths        relative to the searched directory, sorted
     * @param bool         $limitReached the limit was hit, so more files may exist
     */
    public function __construct(
        public array $paths,
        public bool $limitReached,
    ) {}

    /**
     * Sorts the paths and keeps the first $limit of them, as pi does when
     * `fd --max-results` stops.
     *
     * @param list<string> $paths
     */
    public static function fromPaths(array $paths, int $limit): self
    {
        sort($paths, SORT_STRING);

        return new self(array_slice($paths, 0, $limit), count($paths) >= $limit);
    }
}
