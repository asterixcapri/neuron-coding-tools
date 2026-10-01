<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

/**
 * The outcome of {@see Truncation::line()}.
 */
final readonly class TruncatedLine
{
    public function __construct(
        public string $text,
        public bool $wasTruncated,
    ) {}
}
