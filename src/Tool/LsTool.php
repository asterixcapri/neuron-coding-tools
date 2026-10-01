<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\Tool\Support\ListOutput;
use NeuronCodingTools\Tool\Support\PathResolver;
use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function array_diff;
use function array_values;
use function count;
use function error_get_last;
use function file_exists;
use function is_dir;
use function scandir;
use function strcasecmp;
use function usort;

/**
 * pi's `ls` tool: lists a directory's entries, dotfiles included, sorted
 * case-insensitively with a `/` after each directory.
 *
 * The listing is capped at `limit` entries (500 by default) and at 50 KB,
 * whichever is hit first; a note tells the model which `limit` shows more.
 * A missing path or a path that is not a directory is reported by throwing:
 * the Agent's tool error handler turns the message into the result.
 */
final class LsTool extends Tool
{
    protected string $name = 'ls';

    protected ?string $description = "List directory contents. Returns entries sorted alphabetically, with '/' suffix for directories. Includes dotfiles. Output is truncated to " . self::DEFAULT_LIMIT . ' entries or ' . (Truncation::DEFAULT_MAX_BYTES / 1024) . 'KB (whichever is hit first).';

    private const int DEFAULT_LIMIT = 500;

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
                description: 'Directory to list (default: current directory)',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::NUMBER,
                description: 'Maximum number of entries to return (default: 500)',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $path = null, int|float|null $limit = null): string
    {
        $directory = $this->paths->resolve($path === null || $path === '' ? '.' : $path);
        $effectiveLimit = $limit === null ? self::DEFAULT_LIMIT : (int) $limit;

        if (!file_exists($directory)) {
            throw new RuntimeException("Path not found: {$directory}");
        }

        if (!is_dir($directory)) {
            throw new RuntimeException("Not a directory: {$directory}");
        }

        $entries = @scandir($directory);

        if ($entries === false) {
            $error = error_get_last();

            throw new RuntimeException('Cannot read directory: ' . ($error['message'] ?? $directory));
        }

        $entries = array_values(array_diff($entries, ['.', '..']));
        usort($entries, static fn(string $a, string $b): int => strcasecmp($a, $b));

        $results = [];
        $entryLimitReached = false;

        foreach ($entries as $entry) {
            if (count($results) >= $effectiveLimit) {
                $entryLimitReached = true;
                break;
            }

            // Like pi, an entry that cannot be inspected is left out.
            if (!file_exists($directory . '/' . $entry)) {
                continue;
            }

            $results[] = $entry . (is_dir($directory . '/' . $entry) ? '/' : '');
        }

        if ($results === []) {
            return '(empty directory)';
        }

        // The entry count is already capped, so only the byte limit applies.
        return ListOutput::render(
            $results,
            $entryLimitReached ? "{$effectiveLimit} entries limit reached. Use limit=" . ($effectiveLimit * 2) . ' for more' : null,
        );
    }
}
