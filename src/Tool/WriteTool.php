<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\Tool\Support\PathResolver;

use function Amp\File\createDirectoryRecursively;
use function Amp\File\write;
use function dirname;
use function strlen;

/**
 * pi's `write` tool: creates or overwrites a file with the given content,
 * creating missing parent directories on the way.
 *
 * A failure, such as a parent that cannot be created, is reported by
 * throwing: the Agent's tool error handler turns the message into the result.
 */
final class WriteTool extends Tool
{
    protected string $name = 'write';

    protected ?string $description = "Write content to a file. Creates the file if it doesn't exist, overwrites if it does. Automatically creates parent directories.";

    private readonly PathResolver $paths;

    public function __construct(string $workingDirectory)
    {
        $this->paths = new PathResolver($workingDirectory);
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Path to the file to write (relative or absolute)',
                required: true,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::STRING,
                description: 'Content to write to the file',
                required: true,
            ),
        ];
    }

    public function __invoke(string $path, string $content): string
    {
        $absolutePath = $this->paths->resolve($path);

        createDirectoryRecursively(dirname($absolutePath));
        write($absolutePath, $content);

        return 'Successfully wrote ' . strlen($content) . " bytes to {$path}";
    }
}
