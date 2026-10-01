<?php

declare(strict_types=1);

namespace NeuronCodingTools;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronCodingTools\FileSearch\FileSearchInterface;
use NeuronCodingTools\FileSearch\FileSearchSelector;
use NeuronCodingTools\Tool\BashTool;
use NeuronCodingTools\Tool\EditTool;
use NeuronCodingTools\Tool\FindTool;
use NeuronCodingTools\Tool\GrepTool;
use NeuronCodingTools\Tool\LsTool;
use NeuronCodingTools\Tool\ReadTool;
use NeuronCodingTools\Tool\WriteTool;

/**
 * Coding tools rooted at a working directory. Paths may reach outside it;
 * the directory determines relative paths and shell execution, not a sandbox.
 */
final class CodingToolkit extends AbstractToolkit
{
    private readonly FileSearchInterface $fileSearch;

    public function __construct(
        private readonly string $workingDirectory,
        ?FileSearchInterface $fileSearch = null,
    ) {
        $this->fileSearch = $fileSearch ?? FileSearchSelector::select();
    }

    /**
     * @return list<ToolInterface>
     */
    public function provide(): array
    {
        return [
            new ReadTool($this->workingDirectory),
            new BashTool($this->workingDirectory),
            new EditTool($this->workingDirectory),
            new WriteTool($this->workingDirectory),
            new LsTool($this->workingDirectory),
            new FindTool($this->workingDirectory, $this->fileSearch),
            new GrepTool($this->workingDirectory, $this->fileSearch),
        ];
    }
}
