<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

use JsonException;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\ArrayProperty;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/** Accepts models that encode an array argument as a JSON string. */
final class JsonArrayProperty extends ArrayProperty
{
    public function cast(mixed $input): mixed
    {
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidToolInput('must be a valid JSON array', previous: $exception);
            }
        }

        return parent::cast($input);
    }
}
