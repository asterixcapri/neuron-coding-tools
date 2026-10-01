<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\FileSearch\ContentSearchOptions;
use NeuronCodingTools\FileSearch\FileSearchException;
use NeuronCodingTools\FileSearch\FileSearchInterface;
use NeuronCodingTools\FileSearch\FileSearchSelector;
use NeuronCodingTools\FileSearch\PhpFileSearch;
use NeuronCodingTools\FileSearch\RipgrepFileSearch;
use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\GrepTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function file_put_contents;
use function mkdir;
use function str_repeat;

/**
 * Every test runs once per File search implementation; the ripgrep run is
 * skipped when `rg` is not on the PATH.
 */
final class GrepToolTest extends TestCase
{
    use TemporaryDirectory;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'php' => ['php'];
        yield 'ripgrep' => ['ripgrep'];
    }

    protected function setUp(): void
    {
        // The fixture: a small tree with a hidden directory, a fake .git, a
        // binary file, a file named like a directory's sibling (walk order)
        // and a .gitignore covering a file plus vendor and node_modules.
        foreach (['src/deep', '.secret', 'vendor/x', 'node_modules/y', '.git'] as $subdirectory) {
            mkdir($this->directory . '/' . $subdirectory, 0o777, true);
        }

        foreach ([
            'a.ts' => "hello world\nnothing here\nfoo.bar(baz)\nHELLO again\n",
            'src/b.ts' => "Hello there\nplain line\n",
            'src/deep/c.ts' => "hello deep\n",
            'src-x.ts' => "hello sibling\n",
            'example.txt' => "first line\nmatch line\nlast line",
            'context.txt' => "before\nmatch one\nafter\nmiddle\nmatch two\nafter two\n",
            'long.txt' => 'hello ' . str_repeat('x', 600) . "\n",
            'ignored.txt' => "hello ignored\n",
            '.secret/hidden.txt' => "hello hidden\n",
            'vendor/x/z.ts' => "hello vendor\n",
            'node_modules/y/n.ts' => "hello node_modules\n",
            '.git/HEAD' => "hello git\n",
            'bin.dat' => "hello\0binary\n",
        ] as $file => $content) {
            file_put_contents($this->directory . '/' . $file, $content);
        }

        file_put_contents($this->directory . '/.gitignore', "ignored.txt\nvendor/\nnode_modules/\n");
    }

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new GrepTool($this->directory, new PhpFileSearch());

        self::assertSame('grep', $tool->getName());
        self::assertSame(
            'Search file contents for a pattern. Returns matching lines with file paths and line numbers. Respects .gitignore. Output is truncated to 100 matches or 50KB (whichever is hit first). Long lines are truncated to 500 chars.',
            $tool->getDescription(),
        );
        self::assertSame(
            ['pattern', 'path', 'glob', 'ignoreCase', 'literal', 'context', 'limit'],
            array_map(static fn($property) => $property->getName(), $tool->getProperties()),
        );
        self::assertSame(['pattern'], $tool->getRequiredProperties());
    }

    #[DataProvider('implementations')]
    public function testMatchesAreListedAsPathLineAndTextInWalkOrder(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'hello', 'glob' => '*.ts']);

        self::assertSame("a.ts:1: hello world\nsrc/deep/c.ts:1: hello deep\nsrc-x.ts:1: hello sibling", $result);
    }

    #[DataProvider('implementations')]
    public function testSearchingASingleFileStillShowsItsName(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'match', 'path' => $this->directory . '/example.txt']);

        self::assertSame('example.txt:2: match line', $result);
        self::assertSame('example.txt:2: match line', $this->grep($implementation, ['pattern' => 'match', 'path' => 'example.txt']));
    }

    #[DataProvider('implementations')]
    public function testIgnoreCaseMatchesEveryCase(string $implementation): void
    {
        self::assertSame('a.ts:1: hello world', $this->grep($implementation, ['pattern' => 'hello (world|there)']));
        self::assertSame("a.ts:1: hello world\nsrc/b.ts:1: Hello there", $this->grep($implementation, ['pattern' => 'hello (world|there)', 'ignoreCase' => true]));
        self::assertSame("a.ts:1: hello world\na.ts:4: HELLO again", $this->grep($implementation, ['pattern' => 'hello', 'path' => 'a.ts', 'ignoreCase' => true]));
        self::assertSame('a.ts:1: hello world', $this->grep($implementation, ['pattern' => 'hello', 'path' => 'a.ts']));
    }

    #[DataProvider('implementations')]
    public function testLiteralTakesRegexMetacharactersAsTheyAre(string $implementation): void
    {
        self::assertSame('a.ts:3: foo.bar(baz)', $this->grep($implementation, ['pattern' => 'foo.bar(baz)', 'literal' => true]));
        self::assertSame('No matches found', $this->grep($implementation, ['pattern' => 'foo.bar(baz)']));
        self::assertSame('a.ts:3: foo.bar(baz)', $this->grep($implementation, ['pattern' => 'foo\.bar\(baz\)']));
    }

    #[DataProvider('implementations')]
    public function testGlobKeepsOnlyTheMatchingFiles(string $implementation): void
    {
        self::assertSame("src/b.ts:1: Hello there\nsrc/deep/c.ts:1: hello deep", $this->grep($implementation, ['pattern' => 'hello', 'ignoreCase' => true, 'glob' => 'src/**/*.ts']));
        self::assertSame('a.ts:3: foo.bar(baz)', $this->grep($implementation, ['pattern' => 'baz', 'glob' => '*.ts']));
        self::assertSame('No matches found', $this->grep($implementation, ['pattern' => 'baz', 'glob' => '*.txt']));
    }

    #[DataProvider('implementations')]
    public function testContextLinesSurroundEachMatch(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'match', 'path' => 'context.txt', 'context' => 1]);

        self::assertSame(
            "context.txt-1- before\ncontext.txt:2: match one\ncontext.txt-3- after\ncontext.txt-4- middle\ncontext.txt:5: match two\ncontext.txt-6- after two",
            $result,
        );
    }

    #[DataProvider('implementations')]
    public function testOverlappingWindowsRepeatTheirLinesAsPiDoes(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'match', 'path' => 'context.txt', 'context' => 3]);

        self::assertSame(
            "context.txt-1- before\ncontext.txt:2: match one\ncontext.txt-3- after\ncontext.txt-4- middle\ncontext.txt-5- match two\n"
            . "context.txt-2- match one\ncontext.txt-3- after\ncontext.txt-4- middle\ncontext.txt:5: match two\ncontext.txt-6- after two",
            $result,
        );
    }

    #[DataProvider('implementations')]
    public function testLimitStopsTheSearchAndKeepsTheLastContext(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'match', 'path' => 'context.txt', 'limit' => 1, 'context' => 1]);

        self::assertSame(
            "context.txt-1- before\ncontext.txt:2: match one\ncontext.txt-3- after\n\n[1 matches limit reached. Use limit=2 for more, or refine pattern]",
            $result,
        );
        self::assertStringNotContainsString('match two', $result);
    }

    #[DataProvider('implementations')]
    public function testLimitTruncatesAcrossFilesWithPisNote(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'hello', 'glob' => '*.ts', 'limit' => 2]);

        self::assertSame("a.ts:1: hello world\nsrc/deep/c.ts:1: hello deep\n\n[2 matches limit reached. Use limit=4 for more, or refine pattern]", $result);
    }

    #[DataProvider('implementations')]
    public function testLongLinesAreCutAt500CharsWithPisNote(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'hello', 'path' => 'long.txt']);

        self::assertSame(
            'long.txt:1: hello ' . str_repeat('x', 494) . "... [truncated]\n\n[Some lines truncated to 500 chars. Use read tool to see full lines]",
            $result,
        );
    }

    #[DataProvider('implementations')]
    public function testIgnoredExcludedAndBinaryFilesAreSkippedAndHiddenOnesKept(string $implementation): void
    {
        $result = $this->grep($implementation, ['pattern' => 'hello']);

        self::assertStringNotContainsString('ignored.txt', $result);
        self::assertStringNotContainsString('vendor/', $result);
        self::assertStringNotContainsString('node_modules/', $result);
        self::assertStringNotContainsString('.git/', $result);
        self::assertStringNotContainsString('bin.dat', $result);
        self::assertStringContainsString('.secret/hidden.txt:1: hello hidden', $result);
    }

    #[DataProvider('implementations')]
    public function testAMissingPathIsReported(string $implementation): void
    {
        $tool = new GrepTool($this->directory, $this->search($implementation));
        $tool->setInputs(['pattern' => 'hello', 'path' => 'nowhere']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Path not found: {$this->directory}/nowhere");

        $tool->execute();
    }

    #[DataProvider('implementations')]
    public function testAnInvalidRegexIsReported(string $implementation): void
    {
        $tool = new GrepTool($this->directory, $this->search($implementation));
        $tool->setInputs(['pattern' => '[']);

        $this->expectException(FileSearchException::class);
        $this->expectExceptionMessageMatches('/regex parse error/');

        $tool->execute();
    }

    public function testBothImplementationsAgree(): void
    {
        $ripgrep = $this->search('ripgrep');
        $php = new PhpFileSearch();

        $searches = [
            ['hello', new ContentSearchOptions()],
            ['hello', new ContentSearchOptions(ignoreCase: true)],
            ['hello', new ContentSearchOptions(glob: '*.ts', context: 1)],
            ['hello', new ContentSearchOptions(glob: 'src/**', limit: 1)],
            ['foo.bar(baz)', new ContentSearchOptions(literal: true)],
            ['match', new ContentSearchOptions(context: 2)],
            ['match', new ContentSearchOptions(context: 1, limit: 1)],
            ['l', new ContentSearchOptions(limit: 3)],
            ['zzz', new ContentSearchOptions()],
        ];

        foreach ($searches as [$pattern, $options]) {
            self::assertEquals(
                $ripgrep->searchContent($this->directory, $pattern, $options),
                $php->searchContent($this->directory, $pattern, $options),
                "pattern {$pattern}",
            );
        }

        foreach ($searches as [$pattern, $options]) {
            self::assertEquals(
                $ripgrep->searchContent($this->directory . '/context.txt', $pattern, $options),
                $php->searchContent($this->directory . '/context.txt', $pattern, $options),
                "pattern {$pattern} in a single file",
            );
        }
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function grep(string $implementation, array $inputs): string
    {
        $tool = new GrepTool($this->directory, $this->search($implementation));
        $tool->setInputs($inputs);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }

    private function search(string $implementation): FileSearchInterface
    {
        if ($implementation === 'php') {
            return new PhpFileSearch();
        }

        $ripgrep = FileSearchSelector::ripgrepOnPath();

        if ($ripgrep === null) {
            self::markTestSkipped('rg is not on the PATH');
        }

        return new RipgrepFileSearch($ripgrep);
    }
}
