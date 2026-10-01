<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use RuntimeException;

/**
 * A search that could not run: an invalid glob, or the search program
 * failing. The message is what the model should see.
 */
final class FileSearchException extends RuntimeException {}
