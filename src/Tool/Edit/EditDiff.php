<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Edit;

use Normalizer;
use RuntimeException;

use function array_map;
use function array_reverse;
use function count;
use function explode;
use function implode;
use function is_string;
use function preg_replace;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function usort;

/**
 * The pure part of pi's `edit` tool (edit-diff.js): applies a set of
 * replacements to a file's content, matching each `oldText` against the
 * original content, exactly first and then in pi's fuzzy form.
 *
 * The fuzzy form is NFKC-normalised, has trailing whitespace stripped from
 * every line, and maps smart quotes, Unicode dashes and special spaces to
 * their ASCII counterparts. When any replacement needs the fuzzy form, the
 * whole file is rewritten in that form, as pi does. The file's BOM and line
 * ending are restored on the way out. Any failure is reported by throwing
 * with pi's wording.
 */
final class EditDiff
{
    private const string BOM = "\u{FEFF}";

    /**
     * Applies the replacements to a file's raw content and returns what should
     * be written back, keeping the original BOM and line ending.
     *
     * @param list<Replacement> $edits
     */
    public static function apply(string $rawContent, array $edits, string $path): string
    {
        // The model will not include an invisible BOM in oldText.
        $bom = str_starts_with($rawContent, self::BOM) ? self::BOM : '';
        $content = substr($rawContent, strlen($bom));

        $originalEnding = self::detectLineEnding($content);
        $newContent = self::applyToNormalizedContent(self::normalizeToLF($content), $edits, $path);

        return $bom . self::restoreLineEndings($newContent, $originalEnding);
    }

    /**
     * The file is CRLF when its first newline is a CRLF; a file whose first
     * newline is a bare LF is LF even if later lines are CRLF.
     */
    public static function detectLineEnding(string $content): string
    {
        $lfIndex = strpos($content, "\n");
        $crlfIndex = strpos($content, "\r\n");

        if ($lfIndex === false || $crlfIndex === false) {
            return "\n";
        }

        return $crlfIndex < $lfIndex ? "\r\n" : "\n";
    }

    public static function normalizeToLF(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function restoreLineEndings(string $text, string $ending): string
    {
        return $ending === "\r\n" ? str_replace("\n", "\r\n", $text) : $text;
    }

    /**
     * pi's `normalizeForFuzzyMatch`: NFKC, trailing whitespace stripped per
     * line, smart quotes, Unicode dashes and special spaces ASCII-ised.
     */
    public static function normalizeForFuzzyMatch(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        $text = is_string($normalized) ? $normalized : $text;

        // JS trimEnd() strips every Unicode whitespace, including U+FEFF.
        $lines = array_map(
            static fn(string $line): string => preg_replace('/[\s\p{Z}\x{FEFF}]+$/u', '', $line) ?? rtrim($line),
            explode("\n", $text),
        );
        $text = implode("\n", $lines);

        // Smart single quotes: U+2018, U+2019, U+201A, U+201B.
        $text = str_replace(["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}"], "'", $text);
        // Smart double quotes: U+201C, U+201D, U+201E, U+201F.
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}"], '"', $text);
        // U+2010 hyphen, U+2011 non-breaking hyphen, U+2012 figure dash,
        // U+2013 en-dash, U+2014 em-dash, U+2015 horizontal bar, U+2212 minus.
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"], '-', $text);
        // U+00A0 NBSP, U+2002-U+200A various spaces, U+202F narrow NBSP,
        // U+205F medium math space, U+3000 ideographic space.
        return preg_replace('/[\x{00A0}\x{2002}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $text) ?? $text;
    }

    /**
     * pi's `applyEditsToNormalizedContent`: the content and the edits are
     * already LF-normalised; the result is the new LF content.
     *
     * @param list<Replacement> $edits
     */
    public static function applyToNormalizedContent(string $normalizedContent, array $edits, string $path): string
    {
        $normalizedEdits = array_map(
            static fn(Replacement $edit): Replacement => new Replacement(
                self::normalizeToLF($edit->oldText),
                self::normalizeToLF($edit->newText),
            ),
            $edits,
        );
        $totalEdits = count($normalizedEdits);

        foreach ($normalizedEdits as $i => $edit) {
            if ($edit->oldText === '') {
                throw new RuntimeException($totalEdits === 1
                    ? "oldText must not be empty in {$path}."
                    : "edits[{$i}].oldText must not be empty in {$path}.");
            }
        }

        // Every edit is probed against the original content; if any of them
        // needs the fuzzy form, the whole operation runs in that form.
        $needsFuzzy = false;

        foreach ($normalizedEdits as $edit) {
            if (self::find($normalizedContent, $edit->oldText)?->usedFuzzyMatch === true) {
                $needsFuzzy = true;
                break;
            }
        }

        $baseContent = $needsFuzzy ? self::normalizeForFuzzyMatch($normalizedContent) : $normalizedContent;

        $matchedEdits = [];

        foreach ($normalizedEdits as $i => $edit) {
            $match = self::find($baseContent, $edit->oldText);

            if ($match === null) {
                throw new RuntimeException($totalEdits === 1
                    ? "Could not find the exact text in {$path}. The old text must match exactly including all whitespace and newlines."
                    : "Could not find edits[{$i}] in {$path}. The oldText must match exactly including all whitespace and newlines.");
            }

            $occurrences = self::countOccurrences($baseContent, $edit->oldText);

            if ($occurrences > 1) {
                throw new RuntimeException($totalEdits === 1
                    ? "Found {$occurrences} occurrences of the text in {$path}. The text must be unique. Please provide more context to make it unique."
                    : "Found {$occurrences} occurrences of edits[{$i}] in {$path}. Each oldText must be unique. Please provide more context to make it unique.");
            }

            $matchedEdits[] = [
                'editIndex' => $i,
                'matchIndex' => $match->index,
                'matchLength' => $match->length,
                'newText' => $edit->newText,
            ];
        }

        usort($matchedEdits, static fn(array $a, array $b): int => $a['matchIndex'] <=> $b['matchIndex']);

        for ($i = 1; $i < count($matchedEdits); $i++) {
            $previous = $matchedEdits[$i - 1];
            $current = $matchedEdits[$i];

            if ($previous['matchIndex'] + $previous['matchLength'] > $current['matchIndex']) {
                throw new RuntimeException("edits[{$previous['editIndex']}] and edits[{$current['editIndex']}] overlap in {$path}. Merge them into one edit or target disjoint regions.");
            }
        }

        // Applied from the end so the earlier offsets stay valid.
        $newContent = $baseContent;

        foreach (array_reverse($matchedEdits) as $edit) {
            $newContent = substr($newContent, 0, $edit['matchIndex'])
                . $edit['newText']
                . substr($newContent, $edit['matchIndex'] + $edit['matchLength']);
        }

        if ($baseContent === $newContent) {
            throw new RuntimeException($totalEdits === 1
                ? "No changes made to {$path}. The replacement produced identical content. This might indicate an issue with special characters or the text not existing as expected."
                : "No changes made to {$path}. The replacements produced identical content.");
        }

        return $newContent;
    }

    /**
     * pi's `fuzzyFindText`: an exact match, or else a match in the fuzzy form
     * of both texts. The offsets refer to the form the match was found in.
     */
    private static function find(string $content, string $oldText): ?FuzzyMatch
    {
        $exactIndex = strpos($content, $oldText);

        if ($exactIndex !== false) {
            return new FuzzyMatch($exactIndex, strlen($oldText), false);
        }

        $fuzzyContent = self::normalizeForFuzzyMatch($content);
        $fuzzyOldText = self::normalizeForFuzzyMatch($oldText);
        $fuzzyIndex = strpos($fuzzyContent, $fuzzyOldText);

        if ($fuzzyIndex === false) {
            return null;
        }

        return new FuzzyMatch($fuzzyIndex, strlen($fuzzyOldText), true);
    }

    /**
     * Non-overlapping occurrences, always counted in the fuzzy form, so an
     * exact match whose fuzzy form occurs twice counts as a duplicate.
     */
    private static function countOccurrences(string $content, string $oldText): int
    {
        $fuzzyContent = self::normalizeForFuzzyMatch($content);
        $fuzzyOldText = self::normalizeForFuzzyMatch($oldText);

        if ($fuzzyOldText === '') {
            return strlen($fuzzyContent) + 1;
        }

        return substr_count($fuzzyContent, $fuzzyOldText);
    }
}
