<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\FileSearch\ContentMatch;
use NeuronCodingTools\FileSearch\ContentSearchOptions;
use NeuronCodingTools\FileSearch\FileSearchInterface;
use NeuronCodingTools\Tool\Support\ListOutput;
use NeuronCodingTools\Tool\Support\PathResolver;
use NeuronCodingTools\Tool\Support\TruncatedLine;
use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function file_exists;
use function ksort;
use function max;

/**
 * pi's `grep` tool: searches file contents and returns `path:line: text`
 * for every match, with `path-line- text` lines around it when `context`
 * is asked for, paths relative to the searched directory.
 *
 * The search itself is the File search component's, so `.gitignore` is
 * respected. Output stops at `limit` matches (100 by default) or 50 KB,
 * whichever comes first, and single lines are cut at 500 characters, each
 * with pi's note. A missing path and an invalid pattern are reported by
 * throwing: the Agent's tool error handler turns the message into the
 * result.
 */
final class GrepTool extends Tool
{
    protected string $name = 'grep';

    protected ?string $description = 'Search file contents for a pattern. Returns matching lines with file paths and line numbers. Respects .gitignore. Output is truncated to ' . self::DEFAULT_LIMIT . ' matches or ' . (Truncation::DEFAULT_MAX_BYTES / 1024) . 'KB (whichever is hit first). Long lines are truncated to ' . Truncation::GREP_MAX_LINE_LENGTH . ' chars.';

    private const int DEFAULT_LIMIT = 100;

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
                description: 'Search pattern (regex or literal string)',
                required: true,
            ),
            ToolProperty::make(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Directory or file to search (default: current directory)',
                required: false,
            ),
            ToolProperty::make(
                name: 'glob',
                type: PropertyType::STRING,
                description: "Filter files by glob pattern, e.g. '*.ts' or '**/*.spec.ts'",
                required: false,
            ),
            ToolProperty::make(
                name: 'ignoreCase',
                type: PropertyType::BOOLEAN,
                description: 'Case-insensitive search (default: false)',
                required: false,
            ),
            ToolProperty::make(
                name: 'literal',
                type: PropertyType::BOOLEAN,
                description: 'Treat pattern as literal string instead of regex (default: false)',
                required: false,
            ),
            ToolProperty::make(
                name: 'context',
                type: PropertyType::NUMBER,
                description: 'Number of lines to show before and after each match (default: 0)',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::NUMBER,
                description: 'Maximum number of matches to return (default: ' . self::DEFAULT_LIMIT . ')',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $pattern,
        ?string $path = null,
        ?string $glob = null,
        ?bool $ignoreCase = null,
        ?bool $literal = null,
        int|float|null $context = null,
        int|float|null $limit = null,
    ): string {
        $searchPath = $this->paths->resolve($path === null || $path === '' ? '.' : $path);

        if (!file_exists($searchPath)) {
            throw new RuntimeException("Path not found: {$searchPath}");
        }

        $effectiveLimit = max(1, $limit === null ? self::DEFAULT_LIMIT : (int) $limit);
        $options = new ContentSearchOptions(
            glob: $glob === null || $glob === '' ? null : $glob,
            ignoreCase: $ignoreCase === true,
            literal: $literal === true,
            context: $context !== null && $context > 0 ? (int) $context : 0,
            limit: $effectiveLimit,
        );

        $result = $this->search->searchContent($searchPath, $pattern, $options);

        if ($result->matches === []) {
            return 'No matches found';
        }

        $lines = [];
        $linesTruncated = false;

        foreach ($result->matches as $match) {
            foreach (self::block($match) as $line) {
                $lines[] = $line->text;
                $linesTruncated = $linesTruncated || $line->wasTruncated;
            }
        }

        // The match limit already caps the rows, so only the byte limit applies.
        return ListOutput::render(
            $lines,
            $result->limitReached ? "{$effectiveLimit} matches limit reached. Use limit=" . ($effectiveLimit * 2) . ' for more, or refine pattern' : null,
            $linesTruncated ? 'Some lines truncated to ' . Truncation::GREP_MAX_LINE_LENGTH . ' chars. Use read tool to see full lines' : null,
        );
    }

    /**
     * The lines pi prints for one match: its window in line order, the
     * match as `path:line: text` and the others as `path-line- text`.
     *
     * @return list<TruncatedLine>
     */
    private static function block(ContentMatch $match): array
    {
        $window = $match->context + [$match->lineNumber => $match->text];
        ksort($window);
        $block = [];

        foreach ($window as $lineNumber => $text) {
            $cut = Truncation::line($text);
            $separator = $lineNumber === $match->lineNumber ? ':' : '-';
            $block[] = new TruncatedLine("{$match->path}{$separator}{$lineNumber}{$separator} {$cut->text}", $cut->wasTruncated);
        }

        return $block;
    }
}
