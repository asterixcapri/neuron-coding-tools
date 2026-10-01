<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use function preg_last_error;
use function preg_match;

use const PREG_BAD_UTF8_ERROR;

/**
 * A compiled content search pattern: the PCRE regex with the `u` modifier,
 * plus a byte-wise twin for lines that are not valid UTF-8, which the `u`
 * modifier refuses to inspect.
 *
 * @internal
 */
final readonly class LinePattern
{
    public function __construct(
        public string $utf8Regex,
        public string $byteRegex,
    ) {}

    public function matches(string $line): bool
    {
        $result = preg_match($this->utf8Regex, $line);

        if ($result === false && preg_last_error() === PREG_BAD_UTF8_ERROR) {
            $result = preg_match($this->byteRegex, $line);
        }

        return $result === 1;
    }
}
