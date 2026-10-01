<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

/**
 * The outcome of {@see Truncation::head()} or {@see Truncation::tail()},
 * with the same fields as pi's truncation result.
 *
 * Line counts follow pi: the content is split on "\n" only, so a trailing
 * newline yields a final empty line that counts.
 */
final readonly class TruncationResult
{
    public function __construct(
        public string $content,
        public bool $truncated,
        public ?TruncatedBy $truncatedBy,
        public int $totalLines,
        public int $totalBytes,
        public int $outputLines,
        public int $outputBytes,
        /** Only for tail truncation: the single kept line was itself cut. */
        public bool $lastLinePartial,
        /** Only for head truncation: the first line alone exceeds the byte limit. */
        public bool $firstLineExceedsLimit,
        public int $maxLines,
        public int $maxBytes,
    ) {}
}
