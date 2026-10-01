<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\Tool\Support\PathResolver;
use NeuronCodingTools\Tool\Support\TruncatedBy;
use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function Amp\File\isDirectory;
use function Amp\File\isFile;
use function Amp\File\read;
use function array_slice;
use function count;
use function explode;
use function implode;
use function min;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * pi's `read` tool: returns a file's text, optionally a page of it selected
 * with a 1-indexed `offset` and a `limit` in lines.
 *
 * The output is capped at 2000 lines or 50 KB, whichever comes first, and a
 * note tells the model which `offset` continues the file. A binary file is
 * described in one line (its kind and size) instead of being dumped. A
 * missing file, a directory or an offset past the end are reported by
 * throwing: the Agent's tool error handler turns the message into the result.
 */
final class ReadTool extends Tool
{
    protected string $name = 'read';

    protected ?string $description = 'Read the contents of a file. Supports text files and images (jpg, png, gif, webp). Binary files, including images, are described but not returned. For text files, output is truncated to 2000 lines or 50KB (whichever is hit first). Use offset/limit for large files. When you need the full file, continue with offset until complete.';

    /** How many leading bytes are inspected to decide whether a file is binary. */
    private const int SNIFF_BYTES = 8192;

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
                description: 'Path to the file to read (relative or absolute)',
                required: true,
            ),
            ToolProperty::make(
                name: 'offset',
                type: PropertyType::NUMBER,
                description: 'Line number to start reading from (1-indexed)',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::NUMBER,
                description: 'Maximum number of lines to read',
                required: false,
            ),
        ];
    }

    public function __invoke(string $path, int|float|null $offset = null, int|float|null $limit = null): string
    {
        $absolutePath = $this->paths->resolve($path);

        if (isDirectory($absolutePath)) {
            throw new RuntimeException("Path is a directory, not a file: {$path}");
        }

        if (!isFile($absolutePath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = read($absolutePath);
        $kind = self::binaryKind($content);

        if ($kind !== null) {
            return "Binary file ({$kind}, " . Truncation::formatSize(strlen($content)) . '); content not shown.';
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // The model counts from 1; pi treats a missing, zero or negative
        // offset as the first line.
        $startLine = $offset !== null && $offset > 0 ? (int) $offset - 1 : 0;
        $startLineDisplay = $startLine + 1;

        if ($startLine >= $totalLines) {
            throw new RuntimeException("Offset " . (int) $offset . " is beyond end of file ({$totalLines} lines total)");
        }

        $userLimitedLines = null;

        if ($limit !== null) {
            $endLine = min($startLine + (int) $limit, $totalLines);
            $selected = implode("\n", array_slice($lines, $startLine, $endLine - $startLine));
            $userLimitedLines = $endLine - $startLine;
        } else {
            $selected = implode("\n", array_slice($lines, $startLine));
        }

        $truncation = Truncation::head($selected);

        if ($truncation->firstLineExceedsLimit) {
            $firstLineSize = Truncation::formatSize(strlen($lines[$startLine]));
            $limitSize = Truncation::formatSize(Truncation::DEFAULT_MAX_BYTES);

            return "[Line {$startLineDisplay} is {$firstLineSize}, exceeds {$limitSize} limit. Use bash: sed -n '{$startLineDisplay}p' {$path} | head -c " . Truncation::DEFAULT_MAX_BYTES . ']';
        }

        if ($truncation->truncated) {
            $endLineDisplay = $startLineDisplay + $truncation->outputLines - 1;
            $nextOffset = $endLineDisplay + 1;

            if ($truncation->truncatedBy === TruncatedBy::Lines) {
                return $truncation->content . "\n\n[Showing lines {$startLineDisplay}-{$endLineDisplay} of {$totalLines}. Use offset={$nextOffset} to continue.]";
            }

            return $truncation->content . "\n\n[Showing lines {$startLineDisplay}-{$endLineDisplay} of {$totalLines} (" . Truncation::formatSize(Truncation::DEFAULT_MAX_BYTES) . " limit). Use offset={$nextOffset} to continue.]";
        }

        if ($userLimitedLines !== null && $startLine + $userLimitedLines < $totalLines) {
            $remaining = $totalLines - ($startLine + $userLimitedLines);
            $nextOffset = $startLine + $userLimitedLines + 1;

            return $truncation->content . "\n\n[{$remaining} more lines in file. Use offset={$nextOffset} to continue.]";
        }

        return $truncation->content;
    }

    /**
     * The MIME type of a binary file, or null when the content reads as text.
     *
     * The image signatures are the ones pi recognises; any other file with a
     * NUL byte among its first bytes is a binary of unknown kind.
     */
    private static function binaryKind(string $content): ?string
    {
        $head = substr($content, 0, self::SNIFF_BYTES);

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }

        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_contains($head, "\0")) {
            return 'application/octet-stream';
        }

        return null;
    }
}
