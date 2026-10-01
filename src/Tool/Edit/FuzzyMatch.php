<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Edit;

/**
 * Where an `oldText` was found: byte offset and length in the content it
 * was matched against, and whether that content was the fuzzy form.
 */
final readonly class FuzzyMatch
{
    public function __construct(
        public int $index,
        public int $length,
        public bool $usedFuzzyMatch,
    ) {}
}
