<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * The outcome of {@see FileSearchInterface::searchContent()}.
 */
final readonly class ContentSearchResult
{
    /**
     * @param list<ContentMatch> $matches      in file order, files in walk order
     * @param bool               $limitReached the limit was hit, so more matches may exist
     */
    public function __construct(
        public array $matches,
        public bool $limitReached,
    ) {}
}
