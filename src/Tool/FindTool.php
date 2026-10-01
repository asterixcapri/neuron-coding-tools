<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\FileSearch\FileSearchInterface;
use NeuronCodingTools\Tool\Support\ListOutput;
use NeuronCodingTools\Tool\Support\PathResolver;
use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function is_dir;

/**
 * pi's `find` tool: lists the files matching a glob, one path per line,
 * relative to the searched directory.
 *
 * The search itself is the File search component's, so `.gitignore` is
 * respected when ripgrep is installed. The listing stops at `limit` results
 * (1000 by default) or 50 KB, whichever comes first, with pi's notes. A
 * missing directory and an invalid glob are reported by throwing: the
 * Agent's tool error handler turns the message into the result.
 */
final class FindTool extends Tool
{
    protected string $name = 'find';

    protected ?string $description = 'Search for files by glob pattern. Returns matching file paths relative to the search directory. Respects .gitignore. Output is truncated to ' . self::DEFAULT_LIMIT . ' results or ' . (Truncation::DEFAULT_MAX_BYTES / 1024) . 'KB (whichever is hit first).';

    private const int DEFAULT_LIMIT = 1000;

    private readonly PathResolver $paths;

    public function __construct(
        string $workingDirectory,
        private readonly FileSearchInterface $search,
    ) {
        $this->paths = new PathResolver($workingDirectory);
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'pattern',
                type: PropertyType::STRING,
                description: "Glob pattern to match files, e.g. '*.ts', '**/*.json', or 'src/**/*.spec.ts'",
                required: true,
            ),
            ToolProperty::make(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Directory to search in (default: current directory)',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::NUMBER,
                description: 'Maximum number of results (default: ' . self::DEFAULT_LIMIT . ')',
                required: false,
            ),
        ];
    }

    public function __invoke(string $pattern, ?string $path = null, int|float|null $limit = null): string
    {
        $directory = $this->paths->resolve($path === null || $path === '' ? '.' : $path);

        if (!is_dir($directory)) {
            throw new RuntimeException("Path not found: {$directory}");
        }

        $effectiveLimit = $limit !== null && $limit > 0 ? (int) $limit : self::DEFAULT_LIMIT;
        $listing = $this->search->listFiles($directory, $pattern, $effectiveLimit);

        if ($listing->paths === []) {
            return 'No files found matching pattern';
        }

        return ListOutput::render(
            $listing->paths,
            $listing->limitReached ? "{$effectiveLimit} results limit reached. Use limit=" . ($effectiveLimit * 2) . ' for more, or refine pattern' : null,
        );
    }
}
