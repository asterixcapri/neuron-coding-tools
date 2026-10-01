<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

/**
 * One line of a `.gitignore` as {@see IgnoreRules} applies it: the glob
 * compiled to a regex, whether a `!` negates it and whether a trailing `/`
 * restricts it to directories.
 *
 * @internal
 */
final readonly class IgnoreRule
{
    public function __construct(
        public string $regex,
        public bool $negated,
        public bool $directoryOnly,
    ) {}
}
