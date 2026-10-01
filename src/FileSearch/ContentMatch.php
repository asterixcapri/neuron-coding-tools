<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * One matching line, with the lines around it when context was asked for.
 *
 * The window is pi's: every existing line from `lineNumber - context` to
 * `lineNumber + context`, the match line excluded, keyed by line number.
 * Another match falling inside the window is a plain context line here; it
 * has a match of its own with its own window.
 */
final readonly class ContentMatch
{
    /**
     * @param string             $path       relative to the searched directory, or the
     *                                       file's name when a single file was searched
     * @param string             $text       the line without its line ending
     * @param array<int, string> $context    line number => text, ascending
     */
    public function __construct(
        public string $path,
        public int $lineNumber,
        public string $text,
        public array $context = [],
    ) {}
}
