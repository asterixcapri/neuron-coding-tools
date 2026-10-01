<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Edit;

/**
 * One entry of the `edits` array the model sends to `edit`: the text to
 * find in the original file and the text that replaces it.
 */
final readonly class Replacement
{
    public function __construct(
        public string $oldText,
        public string $newText,
    ) {}
}
