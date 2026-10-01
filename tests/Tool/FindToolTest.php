<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\FileSearch\FileSearchException;
use NeuronCodingTools\FileSearch\FileSearchInterface;
use NeuronCodingTools\FileSearch\FileSearchSelector;
use NeuronCodingTools\FileSearch\PhpFileSearch;
use NeuronCodingTools\FileSearch\RipgrepFileSearch;
use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\FindTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function explode;
use function file_put_contents;
use function mkdir;

/**
 * Every test runs once per File search implementation; the ripgrep run is
 * skipped when `rg` is not on the PATH.
 */
final class FindToolTest extends TestCase
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
        // The fixture: a small tree with a hidden directory, a fake .git and
        // a .gitignore covering a file plus vendor and node_modules, which the
        // PHP implementation excludes on its own.
        foreach (['src/deep', '.secret', 'vendor/x', 'node_modules/y', '.git'] as $subdirectory) {
            mkdir($this->directory . '/' . $subdirectory, 0o777, true);
        }

        foreach ([
            'a.ts', 'src/b.ts', 'src/b.spec.ts', 'src/deep/c.ts', 'visible.txt', 'ignored.txt',
            '.secret/hidden.txt', 'vendor/x/z.ts', 'node_modules/y/n.ts', '.git/HEAD',
        ] as $file) {
            file_put_contents($this->directory . '/' . $file, $file);
        }

        file_put_contents($this->directory . '/.gitignore', "ignored.txt\nvendor/\nnode_modules/\n");
    }

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new FindTool($this->directory, new PhpFileSearch());

        self::assertSame('find', $tool->getName());
        self::assertSame(
            'Search for files by glob pattern. Returns matching file paths relative to the search directory. Respects .gitignore. Output is truncated to 1000 results or 50KB (whichever is hit first).',
            $tool->getDescription(),
        );
        self::assertSame(['pattern', 'path', 'limit'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame(['pattern'], $tool->getRequiredProperties());
    }

    #[DataProvider('implementations')]
    public function testAPatternWithoutASlashMatchesFileNamesAtAnyDepth(string $implementation): void
    {
        $result = $this->find($implementation, ['pattern' => '*.ts']);

        self::assertSame("a.ts\nsrc/b.spec.ts\nsrc/b.ts\nsrc/deep/c.ts", $result);
    }

    #[DataProvider('implementations')]
    public function testVendorNodeModulesAndGitAreExcluded(string $implementation): void
    {
        $result = $this->find($implementation, ['pattern' => '**']);

        self::assertStringNotContainsString('vendor/', $result);
        self::assertStringNotContainsString('node_modules/', $result);
        self::assertStringNotContainsString('.git/', $result);
        self::assertStringContainsString("\n.gitignore\n", "\n{$result}\n");
    }

    #[DataProvider('implementations')]
    public function testHiddenFilesThatAreNotIgnoredAreIncluded(string $implementation): void
    {
        $lines = explode("\n", $this->find($implementation, ['pattern' => '**/*.txt']));

        self::assertContains('visible.txt', $lines);
        self::assertContains('.secret/hidden.txt', $lines);
    }

    #[DataProvider('implementations')]
    public function testGitignoreIsRespected(string $implementation): void
    {
        $result = $this->find($implementation, ['pattern' => '**/*.txt']);

        self::assertStringContainsString('visible.txt', $result);
        self::assertStringNotContainsString('ignored.txt', $result);
    }

    #[DataProvider('implementations')]
    public function testAPatternWithASlashMatchesThePath(string $implementation): void
    {
        self::assertSame('src/b.spec.ts', $this->find($implementation, ['pattern' => 'src/**/*.spec.ts']));
        self::assertSame('src/deep/c.ts', $this->find($implementation, ['pattern' => 'src/deep/*.ts']));
        self::assertSame("src/b.spec.ts\nsrc/b.ts", $this->find($implementation, ['pattern' => 'src/*.ts']));
    }

    #[DataProvider('implementations')]
    public function testPathSelectsTheDirectoryAndResultsAreRelativeToIt(string $implementation): void
    {
        self::assertSame("b.spec.ts\nb.ts\ndeep/c.ts", $this->find($implementation, ['pattern' => '*.ts', 'path' => 'src']));
        self::assertSame("b.spec.ts\nb.ts\ndeep/c.ts", $this->find($implementation, ['pattern' => '*.ts', 'path' => $this->directory . '/src']));
    }

    #[DataProvider('implementations')]
    public function testLimitStopsTheListingWithPisNote(string $implementation): void
    {
        $result = $this->find($implementation, ['pattern' => '*.ts', 'limit' => 2]);

        self::assertSame("a.ts\nsrc/b.spec.ts\n\n[2 results limit reached. Use limit=4 for more, or refine pattern]", $result);
    }

    #[DataProvider('implementations')]
    public function testANameWithLeadingAndTrailingSpacesIsKeptIntact(string $implementation): void
    {
        file_put_contents($this->directory . '/ spaced .txt', '');

        self::assertSame(' spaced .txt', $this->find($implementation, ['pattern' => '* spaced *']));
    }

    #[DataProvider('implementations')]
    public function testNoMatchIsReportedInPisWords(string $implementation): void
    {
        self::assertSame('No files found matching pattern', $this->find($implementation, ['pattern' => '*.zzz']));
    }

    #[DataProvider('implementations')]
    public function testAMissingDirectoryIsReported(string $implementation): void
    {
        $tool = new FindTool($this->directory, $this->search($implementation));
        $tool->setInputs(['pattern' => '*.ts', 'path' => 'nowhere']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Path not found: {$this->directory}/nowhere");

        $tool->execute();
    }

    #[DataProvider('implementations')]
    public function testAnInvalidGlobIsReported(string $implementation): void
    {
        $tool = new FindTool($this->directory, $this->search($implementation));
        $tool->setInputs(['pattern' => '[']);

        $this->expectException(FileSearchException::class);
        $this->expectExceptionMessageMatches('/error parsing glob/i');

        $tool->execute();
    }

    public function testBothImplementationsAgree(): void
    {
        $ripgrep = $this->search('ripgrep');
        $php = new PhpFileSearch();

        foreach (['*.ts', '**', '**/*.txt', 'src/**/*.spec.ts', '*.{ts,txt}', '[ab].ts', 'deep/?.ts'] as $pattern) {
            self::assertEquals(
                $ripgrep->listFiles($this->directory, $pattern, 1000),
                $php->listFiles($this->directory, $pattern, 1000),
                "pattern {$pattern}",
            );
        }
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function find(string $implementation, array $inputs): string
    {
        $tool = new FindTool($this->directory, $this->search($implementation));
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
