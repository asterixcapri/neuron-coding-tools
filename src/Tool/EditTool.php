<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool;

use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCodingTools\Tool\Edit\EditDiff;
use NeuronCodingTools\Tool\Edit\Replacement;
use NeuronCodingTools\Tool\Support\JsonArrayProperty;
use NeuronCodingTools\Tool\Support\PathResolver;
use RuntimeException;

use function Amp\File\isFile;
use function Amp\File\read;
use function Amp\File\write;
use function array_is_list;
use function count;
use function is_array;
use function is_string;
use function json_decode;

/**
 * pi's `edit` tool: applies one or more targeted replacements to a file in
 * one call. Each `oldText` is matched against the original file, exactly or
 * in pi's fuzzy form, must be unique and must not overlap another edit; the
 * file keeps its BOM and line ending.
 *
 * Invalid input, a missing file and every matching failure are reported by
 * throwing: the Agent's tool error handler turns the message into the result.
 */
final class EditTool extends Tool
{
    protected string $name = 'edit';

    protected ?string $description = 'Edit a single file using exact text replacement. Every edits[].oldText must match a unique, non-overlapping region of the original file. If two changes affect the same block or nearby lines, merge them into one edit instead of emitting overlapping edits. Do not include large unchanged regions just to connect distant changes.';

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
                description: 'Path to the file to edit (relative or absolute)',
                required: true,
            ),
            JsonArrayProperty::make(
                name: 'edits',
                description: 'One or more targeted replacements. Each edit is matched against the original file, not incrementally. Do not include overlapping or nested edits. If two changes touch the same block or nearby lines, merge them into one edit instead.',
                required: true,
                items: new ObjectProperty(
                    name: 'edit',
                    properties: [
                        ToolProperty::make(
                            name: 'oldText',
                            type: PropertyType::STRING,
                            description: 'Exact text for one targeted replacement. It must be unique in the original file and must not overlap with any other edits[].oldText in the same call.',
                            required: true,
                        ),
                        ToolProperty::make(
                            name: 'newText',
                            type: PropertyType::STRING,
                            description: 'Replacement text for this targeted edit.',
                            required: true,
                        ),
                    ],
                ),
            ),
        ];
    }

    /**
     * @param array<mixed>|string $edits the array of `{oldText, newText}`
     *                                   objects, or that array as a JSON
     *                                   string, which some models send
     */
    public function __invoke(string $path, array|string $edits): string
    {
        $replacements = self::replacements($edits);
        $absolutePath = $this->paths->resolve($path);

        if (!isFile($absolutePath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        write($absolutePath, EditDiff::apply(read($absolutePath), $replacements, $path));

        return 'Successfully replaced ' . count($replacements) . " block(s) in {$path}.";
    }

    /**
     * @param array<mixed>|string $edits
     *
     * @return list<Replacement>
     */
    private static function replacements(array|string $edits): array
    {
        if (is_string($edits)) {
            $decoded = json_decode($edits, true);
            $edits = is_array($decoded) ? $decoded : [];
        }

        if (!array_is_list($edits) || $edits === []) {
            throw new RuntimeException('Edit tool input is invalid. edits must contain at least one replacement.');
        }

        $replacements = [];

        foreach ($edits as $i => $edit) {
            if (!is_array($edit) || !is_string($edit['oldText'] ?? null) || !is_string($edit['newText'] ?? null)) {
                throw new RuntimeException("Edit tool input is invalid. edits[{$i}] must have string oldText and newText.");
            }

            $replacements[] = new Replacement($edit['oldText'], $edit['newText']);
        }

        return $replacements;
    }
}
