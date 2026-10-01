<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tests\Tool;

use NeuronCodingTools\Tests\Support\TemporaryDirectory;
use NeuronCodingTools\Tool\EditTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function file_get_contents;
use function file_put_contents;

/**
 * pi's `edit tool`, `edit tool fuzzy matching` and `edit tool CRLF handling`
 * test cases, run through the Tool seam against a temporary directory.
 */
final class EditToolTest extends TestCase
{
    use TemporaryDirectory;

    public function testItIsMountedWithPisNameAndParameters(): void
    {
        $tool = new EditTool($this->directory);

        self::assertSame('edit', $tool->getName());
        self::assertStringStartsWith('Edit a single file using exact text replacement.', $tool->getDescription() ?? '');
        self::assertSame(['path', 'edits'], array_map(static fn($property) => $property->getName(), $tool->getProperties()));
        self::assertSame(['path', 'edits'], $tool->getRequiredProperties());

        self::assertSame([
            'type' => 'array',
            'description' => 'One or more targeted replacements. Each edit is matched against the original file, not incrementally. Do not include overlapping or nested edits. If two changes touch the same block or nearby lines, merge them into one edit instead.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'oldText' => [
                        'type' => 'string',
                        'description' => 'Exact text for one targeted replacement. It must be unique in the original file and must not overlap with any other edits[].oldText in the same call.',
                    ],
                    'newText' => [
                        'type' => 'string',
                        'description' => 'Replacement text for this targeted edit.',
                    ],
                ],
                'required' => ['oldText', 'newText'],
            ],
        ], $tool->getProperties()[1]->getJsonSchema());
    }

    public function testItReplacesTextInAFile(): void
    {
        $this->file('edit-test.txt', 'Hello, world!');

        $result = $this->edit('edit-test.txt', [['oldText' => 'world', 'newText' => 'testing']]);

        self::assertSame('Successfully replaced 1 block(s) in edit-test.txt.', $result);
        self::assertSame('Hello, testing!', $this->contents('edit-test.txt'));
    }

    public function testAnAbsolutePathIsAccepted(): void
    {
        $path = $this->file('absolute.txt', 'Hello, world!');

        $result = $this->edit($path, [['oldText' => 'world', 'newText' => 'testing']]);

        self::assertSame("Successfully replaced 1 block(s) in {$path}.", $result);
        self::assertSame('Hello, testing!', $this->contents('absolute.txt'));
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectExceptionMessage('File not found: nonexistent.txt');

        $this->edit('nonexistent.txt', [['oldText' => 'a', 'newText' => 'b']]);
    }

    public function testItFailsIfTheTextIsNotFound(): void
    {
        $this->file('edit-test.txt', 'Hello, world!');

        $this->expectExceptionMessage('Could not find the exact text in edit-test.txt. The old text must match exactly including all whitespace and newlines.');

        $this->edit('edit-test.txt', [['oldText' => 'nonexistent', 'newText' => 'testing']]);
    }

    public function testItFailsIfTheTextAppearsMultipleTimes(): void
    {
        $this->file('edit-test.txt', 'foo foo foo');

        $this->expectExceptionMessage('Found 3 occurrences of the text in edit-test.txt. The text must be unique. Please provide more context to make it unique.');

        $this->edit('edit-test.txt', [['oldText' => 'foo', 'newText' => 'bar']]);
    }

    public function testItReplacesMultipleDisjointRegionsInOneCall(): void
    {
        $this->file('edit-multi.txt', "alpha\nbeta\ngamma\ndelta\n");

        $result = $this->edit('edit-multi.txt', [
            ['oldText' => "alpha\n", 'newText' => "ALPHA\n"],
            ['oldText' => "gamma\n", 'newText' => "GAMMA\n"],
        ]);

        self::assertSame('Successfully replaced 2 block(s) in edit-multi.txt.', $result);
        self::assertSame("ALPHA\nbeta\nGAMMA\ndelta\n", $this->contents('edit-multi.txt'));
    }

    public function testItMatchesEditsAgainstTheOriginalFileNotIncrementally(): void
    {
        $this->file('edit-multi-original.txt', "foo\nbar\nbaz\n");

        $this->edit('edit-multi-original.txt', [
            ['oldText' => "foo\n", 'newText' => "foo bar\n"],
            ['oldText' => "bar\n", 'newText' => "BAR\n"],
        ]);

        self::assertSame("foo bar\nBAR\nbaz\n", $this->contents('edit-multi-original.txt'));
    }

    public function testItFailsWhenEditsIsEmpty(): void
    {
        $this->file('edit-empty-edits.txt', "hello\nworld\n");

        $this->expectExceptionMessage('Edit tool input is invalid. edits must contain at least one replacement.');

        $this->edit('edit-empty-edits.txt', []);
    }

    public function testItFailsWhenAnOldTextIsEmpty(): void
    {
        $this->file('edit-empty-old.txt', "hello\nworld\n");

        $this->expectExceptionMessage('edits[1].oldText must not be empty in edit-empty-old.txt.');

        $this->edit('edit-empty-old.txt', [
            ['oldText' => 'hello', 'newText' => 'HELLO'],
            ['oldText' => '', 'newText' => 'x'],
        ]);
    }

    public function testItFailsWhenMultiEditRegionsOverlap(): void
    {
        $this->file('edit-overlap.txt', "one\ntwo\nthree\n");

        $this->expectExceptionMessage('edits[0] and edits[1] overlap in edit-overlap.txt. Merge them into one edit or target disjoint regions.');

        $this->edit('edit-overlap.txt', [
            ['oldText' => "one\ntwo\n", 'newText' => "ONE\nTWO\n"],
            ['oldText' => "two\nthree\n", 'newText' => "TWO\nTHREE\n"],
        ]);
    }

    public function testItDoesNotPartiallyApplyEditsWhenOneEditFails(): void
    {
        $this->file('edit-no-partial.txt', "alpha\nbeta\ngamma\n");

        try {
            $this->edit('edit-no-partial.txt', [
                ['oldText' => "alpha\n", 'newText' => "ALPHA\n"],
                ['oldText' => "missing\n", 'newText' => "MISSING\n"],
            ]);
            self::fail('The edit should have failed.');
        } catch (RuntimeException $e) {
            self::assertSame('Could not find edits[1] in edit-no-partial.txt. The oldText must match exactly including all whitespace and newlines.', $e->getMessage());
        }

        self::assertSame("alpha\nbeta\ngamma\n", $this->contents('edit-no-partial.txt'));
    }

    public function testAReplacementThatChangesNothingIsReported(): void
    {
        $this->file('edit-noop.txt', "alpha\nbeta\n");

        $this->expectExceptionMessage('No changes made to edit-noop.txt. The replacement produced identical content. This might indicate an issue with special characters or the text not existing as expected.');

        $this->edit('edit-noop.txt', [['oldText' => 'alpha', 'newText' => 'alpha']]);
    }

    public function testEditsSentAsAJsonStringAreAccepted(): void
    {
        $this->file('edit-json.txt', "alpha\nbeta\n");

        $result = $this->edit('edit-json.txt', '[{"oldText":"beta","newText":"BETA"}]');

        self::assertSame('Successfully replaced 1 block(s) in edit-json.txt.', $result);
        self::assertSame("alpha\nBETA\n", $this->contents('edit-json.txt'));
    }

    public function testItMatchesTextWithTrailingWhitespaceStripped(): void
    {
        $this->file('trailing-ws.txt', "line one   \nline two  \nline three\n");

        $result = $this->edit('trailing-ws.txt', [['oldText' => "line one\nline two\n", 'newText' => "replaced\n"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("replaced\nline three\n", $this->contents('trailing-ws.txt'));
    }

    public function testItMatchesFullwidthPunctuationInChineseText(): void
    {
        $this->file('chinese-punctuation.txt', "你好，世界\n你好（世界）\n");

        $result = $this->edit('chinese-punctuation.txt', [['oldText' => "你好,世界\n你好(世界)\n", 'newText' => "你好，pi\n你好(pi)\n"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("你好，pi\n你好(pi)\n", $this->contents('chinese-punctuation.txt'));
    }

    public function testItMatchesCompatibilityEquivalentUnicodeForms(): void
    {
        $this->file('unicode-compatibility.txt', "ＡＢＣ１２３\ncafe\u{0301}\n");

        $result = $this->edit('unicode-compatibility.txt', [['oldText' => "ABC123\ncafé\n", 'newText' => "XYZ789\ncoffee\n"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("XYZ789\ncoffee\n", $this->contents('unicode-compatibility.txt'));
    }

    public function testItMatchesSmartSingleQuotesToAsciiQuotes(): void
    {
        $this->file('smart-quotes.txt', "console.log(\u{2018}hello\u{2019});\n");

        $result = $this->edit('smart-quotes.txt', [['oldText' => "console.log('hello');", 'newText' => "console.log('world');"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("console.log('world');\n", $this->contents('smart-quotes.txt'));
    }

    public function testItMatchesSmartDoubleQuotesToAsciiQuotes(): void
    {
        $this->file('smart-double-quotes.txt', "const msg = \u{201C}Hello World\u{201D};\n");

        $result = $this->edit('smart-double-quotes.txt', [['oldText' => 'const msg = "Hello World";', 'newText' => 'const msg = "Goodbye";']]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("const msg = \"Goodbye\";\n", $this->contents('smart-double-quotes.txt'));
    }

    public function testItMatchesUnicodeDashesToAsciiHyphen(): void
    {
        $this->file('unicode-dashes.txt', "range: 1\u{2013}5\nbreak\u{2014}here\n");

        $result = $this->edit('unicode-dashes.txt', [['oldText' => "range: 1-5\nbreak-here", 'newText' => "range: 10-50\nbreak--here"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("range: 10-50\nbreak--here\n", $this->contents('unicode-dashes.txt'));
    }

    public function testItMatchesNonBreakingSpaceToRegularSpace(): void
    {
        $this->file('nbsp.txt', "hello\u{00A0}world\n");

        $result = $this->edit('nbsp.txt', [['oldText' => 'hello world', 'newText' => 'hello universe']]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("hello universe\n", $this->contents('nbsp.txt'));
    }

    public function testItPrefersAnExactMatchOverAFuzzyMatch(): void
    {
        $this->file('exact-preferred.txt', "const x = 'exact';\nconst y = 'other';\n");

        $result = $this->edit('exact-preferred.txt', [['oldText' => "const x = 'exact';", 'newText' => "const x = 'changed';"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("const x = 'changed';\nconst y = 'other';\n", $this->contents('exact-preferred.txt'));
    }

    public function testItStillFailsWhenTheTextIsNotFoundEvenWithFuzzyMatching(): void
    {
        $this->file('no-match.txt', "completely different content\n");

        $this->expectExceptionMessage('Could not find the exact text in no-match.txt.');

        $this->edit('no-match.txt', [['oldText' => 'this does not exist', 'newText' => 'replacement']]);
    }

    public function testItDetectsDuplicatesAfterFuzzyNormalization(): void
    {
        $this->file('fuzzy-dups.txt', "hello world   \nhello world\n");

        $this->expectExceptionMessage('Found 2 occurrences of the text in fuzzy-dups.txt.');

        $this->edit('fuzzy-dups.txt', [['oldText' => 'hello world', 'newText' => 'replaced']]);
    }

    public function testItSupportsFuzzyMatchingInMultiEditMode(): void
    {
        $this->file('fuzzy-multi.txt', "console.log(\u{2018}hello\u{2019});\nhello\u{00A0}world\n");

        $this->edit('fuzzy-multi.txt', [
            ['oldText' => "console.log('hello');\n", 'newText' => "console.log('world');\n"],
            ['oldText' => "hello world\n", 'newText' => "hello universe\n"],
        ]);

        self::assertSame("console.log('world');\nhello universe\n", $this->contents('fuzzy-multi.txt'));
    }

    public function testItMatchesLfOldTextAgainstCrlfFileContent(): void
    {
        $this->file('crlf-test.txt', "line one\r\nline two\r\nline three\r\n");

        $result = $this->edit('crlf-test.txt', [['oldText' => "line two\n", 'newText' => "replaced line\n"]]);

        self::assertStringContainsString('Successfully replaced', $result);
        self::assertSame("line one\r\nreplaced line\r\nline three\r\n", $this->contents('crlf-test.txt'));
    }

    public function testItPreservesCrlfLineEndingsAfterEdit(): void
    {
        $this->file('crlf-preserve.txt', "first\r\nsecond\r\nthird\r\n");

        $this->edit('crlf-preserve.txt', [['oldText' => "second\n", 'newText' => "REPLACED\n"]]);

        self::assertSame("first\r\nREPLACED\r\nthird\r\n", $this->contents('crlf-preserve.txt'));
    }

    public function testItPreservesLfLineEndingsForLfFiles(): void
    {
        $this->file('lf-preserve.txt', "first\nsecond\nthird\n");

        $this->edit('lf-preserve.txt', [['oldText' => "second\n", 'newText' => "REPLACED\n"]]);

        self::assertSame("first\nREPLACED\nthird\n", $this->contents('lf-preserve.txt'));
    }

    public function testItDetectsDuplicatesAcrossCrlfAndLfVariants(): void
    {
        $this->file('mixed-endings.txt', "hello\r\nworld\r\n---\r\nhello\nworld\n");

        $this->expectExceptionMessage('Found 2 occurrences of the text in mixed-endings.txt.');

        $this->edit('mixed-endings.txt', [['oldText' => "hello\nworld\n", 'newText' => "replaced\n"]]);
    }

    public function testItPreservesTheUtf8BomAfterEdit(): void
    {
        $this->file('bom-test.txt', "\u{FEFF}first\r\nsecond\r\nthird\r\n");

        $this->edit('bom-test.txt', [['oldText' => "second\n", 'newText' => "REPLACED\n"]]);

        self::assertSame("\u{FEFF}first\r\nREPLACED\r\nthird\r\n", $this->contents('bom-test.txt'));
    }

    public function testItPreservesCrlfLineEndingsAndBomInMultiEditMode(): void
    {
        $this->file('bom-crlf-multi.txt', "\u{FEFF}first\r\nsecond\r\nthird\r\nfourth\r\n");

        $this->edit('bom-crlf-multi.txt', [
            ['oldText' => "second\n", 'newText' => "SECOND\n"],
            ['oldText' => "fourth\n", 'newText' => "FOURTH\n"],
        ]);

        self::assertSame("\u{FEFF}first\r\nSECOND\r\nthird\r\nFOURTH\r\n", $this->contents('bom-crlf-multi.txt'));
    }

    /**
     * @param list<array{oldText: string, newText: string}>|string $edits
     */
    private function edit(string $path, array|string $edits): string
    {
        $tool = new EditTool($this->directory);
        $tool->setInputs(['path' => $path, 'edits' => $edits]);
        $tool->execute();

        $result = $tool->getResult();
        self::assertIsString($result);

        return $result;
    }

    private function file(string $name, string $content): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function contents(string $name): string
    {
        return (string) file_get_contents($this->directory . '/' . $name);
    }
}
