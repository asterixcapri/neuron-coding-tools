<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * How {@see FileSearchInterface::searchContent()} should search: pi's
 * `grep` parameters, with the same defaults.
 */
final readonly class ContentSearchOptions
{
    /**
     * @param string|null $glob       keeps only the files whose path matches this glob
     * @param bool        $ignoreCase case-insensitive matching
     * @param bool        $literal    the pattern is a literal string, not a regex
     * @param int         $context    lines to collect before and after each match, 0 for none
     * @param int         $limit      a positive count of matches after which the search stops
     */
    public function __construct(
        public ?string $glob = null,
        public bool $ignoreCase = false,
        public bool $literal = false,
        public int $context = 0,
        public int $limit = 100,
    ) {}
}
