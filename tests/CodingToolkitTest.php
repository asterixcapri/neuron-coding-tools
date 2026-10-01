<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronCodingTools\CodingToolkit;
use NeuronCodingTools\FileSearch\PhpFileSearch;
use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\FindTool;
use NeuronCodingTools\Tool\ReadTool;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;

final class CodingToolkitTest extends TestCase
{
    use TemporaryDirectory;

    public function testAnAgentCanReadAndSearchWithTheToolkit(): void
    {
        file_put_contents($this->directory . '/hello.txt', "Hello toolkit\n");
        $read = new ToolCall(name: 'read', callId: 'read-call', inputs: ['path' => 'hello.txt']);
        $grep = new ToolCall(name: 'grep', callId: 'grep-call', inputs: ['pattern' => 'Hello']);
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$read, $grep]),
            new AssistantMessage('Done.'),
        );
        $agent = new Agent();
        $agent->addTool(new CodingToolkit($this->directory, new PhpFileSearch()));
        $agent->setThreadId('coding-toolkit-test')->setAiProvider($provider);

        $agent->chat(new UserMessage('Read and search hello.txt'))->getMessage();

        self::assertSame("Hello toolkit\n", $read->getResult());
        self::assertSame('hello.txt:1: Hello toolkit', $grep->getResult());
        self::assertSame(
            ['read', 'bash', 'edit', 'write', 'ls', 'find', 'grep'],
            array_map(static fn($tool) => $tool->getName(), $provider->getRecorded()[0]->tools),
        );
        $provider->assertCallCount(2);
    }

    public function testDefaultSearchAndToolkitFilteringWork(): void
    {
        file_put_contents($this->directory . '/hello.txt', 'Hello');
        $toolkit = new CodingToolkit($this->directory);
        $tools = $toolkit->only([ReadTool::class])->tools();

        self::assertCount(1, $tools);
        self::assertInstanceOf(ReadTool::class, $tools[0]);
        self::assertSame('Hello', $tools[0]('hello.txt'));

        $searchTools = $toolkit->only([FindTool::class])->tools();
        self::assertCount(1, $searchTools);
        self::assertInstanceOf(FindTool::class, $searchTools[0]);
        self::assertSame('hello.txt', $searchTools[0]('*.txt'));
    }
}
