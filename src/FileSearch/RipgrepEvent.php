<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * One `rg --json` file event reduced to what the search needs: its type
 * (`begin`, `match`, `context` or `end`), the file's relative path, and for
 * line events the line number and text.
 *
 * @internal
 */
final readonly class RipgrepEvent
{
    public function __construct(
        public string $type,
        public string $path,
        public ?int $lineNumber,
        public ?string $text,
    ) {}
}
