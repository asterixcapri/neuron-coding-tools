<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

/**
 * Which limit a truncation hit first.
 */
enum TruncatedBy: string
{
    case Lines = 'lines';
    case Bytes = 'bytes';
}
