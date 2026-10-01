<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

use function array_unshift;
use function count;
use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;
use function ord;
use function sprintf;
use function strlen;
use function substr;

/**
 * Output limits shared by the Tools, ported from pi's truncate.js.
 *
 * Every Tool caps what the model sees at {@see self::DEFAULT_MAX_LINES} lines
 * or {@see self::DEFAULT_MAX_BYTES} bytes, whichever is hit first. `read`
 * keeps the head of its content, `bash` the tail, `grep` shortens single
 * lines to {@see self::GREP_MAX_LINE_LENGTH} characters.
 */
final class Truncation
{
    public const int DEFAULT_MAX_LINES = 2000;
    public const int DEFAULT_MAX_BYTES = 50 * 1024;
    public const int GREP_MAX_LINE_LENGTH = 500;

    /**
     * Keeps the first lines that fit within both limits.
     *
     * When the very first line alone exceeds the byte limit nothing is kept
     * and `firstLineExceedsLimit` is set, so the caller can point the model
     * at a different way to read it.
     */
    public static function head(
        string $content,
        int $maxLines = self::DEFAULT_MAX_LINES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): TruncationResult {
        $totalBytes = strlen($content);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($totalLines <= $maxLines && $totalBytes <= $maxBytes) {
            return self::untruncated($content, $totalLines, $totalBytes, $maxLines, $maxBytes);
        }

        if (strlen($lines[0]) > $maxBytes) {
            return new TruncationResult(
                content: '',
                truncated: true,
                truncatedBy: TruncatedBy::Bytes,
                totalLines: $totalLines,
                totalBytes: $totalBytes,
                outputLines: 0,
                outputBytes: 0,
                lastLinePartial: false,
                firstLineExceedsLimit: true,
                maxLines: $maxLines,
                maxBytes: $maxBytes,
            );
        }

        $output = [];
        $outputBytes = 0;
        $truncatedBy = TruncatedBy::Lines;

        for ($i = 0; $i < $totalLines && $i < $maxLines; $i++) {
            $lineBytes = strlen($lines[$i]) + ($i > 0 ? 1 : 0);

            if ($outputBytes + $lineBytes > $maxBytes) {
                $truncatedBy = TruncatedBy::Bytes;
                break;
            }

            $output[] = $lines[$i];
            $outputBytes += $lineBytes;
        }

        if (count($output) >= $maxLines && $outputBytes <= $maxBytes) {
            $truncatedBy = TruncatedBy::Lines;
        }

        $outputContent = implode("\n", $output);

        return new TruncationResult(
            content: $outputContent,
            truncated: true,
            truncatedBy: $truncatedBy,
            totalLines: $totalLines,
            totalBytes: $totalBytes,
            outputLines: count($output),
            outputBytes: strlen($outputContent),
            lastLinePartial: false,
            firstLineExceedsLimit: false,
            maxLines: $maxLines,
            maxBytes: $maxBytes,
        );
    }

    /**
     * Keeps the last lines that fit within both limits.
     *
     * When even the last line alone exceeds the byte limit, its tail is kept
     * and `lastLinePartial` is set.
     */
    public static function tail(
        string $content,
        int $maxLines = self::DEFAULT_MAX_LINES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): TruncationResult {
        $totalBytes = strlen($content);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($totalLines <= $maxLines && $totalBytes <= $maxBytes) {
            return self::untruncated($content, $totalLines, $totalBytes, $maxLines, $maxBytes);
        }

        $output = [];
        $outputBytes = 0;
        $truncatedBy = TruncatedBy::Lines;
        $lastLinePartial = false;

        for ($i = $totalLines - 1; $i >= 0 && count($output) < $maxLines; $i--) {
            $lineBytes = strlen($lines[$i]) + ($output !== [] ? 1 : 0);

            if ($outputBytes + $lineBytes > $maxBytes) {
                $truncatedBy = TruncatedBy::Bytes;

                if ($output === []) {
                    $truncatedLine = self::tailBytes($lines[$i], $maxBytes);
                    array_unshift($output, $truncatedLine);
                    $outputBytes = strlen($truncatedLine);
                    $lastLinePartial = true;
                }

                break;
            }

            array_unshift($output, $lines[$i]);
            $outputBytes += $lineBytes;
        }

        if (count($output) >= $maxLines && $outputBytes <= $maxBytes) {
            $truncatedBy = TruncatedBy::Lines;
        }

        $outputContent = implode("\n", $output);

        return new TruncationResult(
            content: $outputContent,
            truncated: true,
            truncatedBy: $truncatedBy,
            totalLines: $totalLines,
            totalBytes: $totalBytes,
            outputLines: count($output),
            outputBytes: strlen($outputContent),
            lastLinePartial: $lastLinePartial,
            firstLineExceedsLimit: false,
            maxLines: $maxLines,
            maxBytes: $maxBytes,
        );
    }

    /**
     * Shortens a single line to at most $maxChars characters, marking the cut.
     */
    public static function line(string $line, int $maxChars = self::GREP_MAX_LINE_LENGTH): TruncatedLine
    {
        if (mb_strlen($line) <= $maxChars) {
            return new TruncatedLine($line, false);
        }

        return new TruncatedLine(mb_substr($line, 0, $maxChars) . '... [truncated]', true);
    }

    /**
     * A byte count as pi prints it: `512B`, `50.0KB`, `1.2MB`.
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1fKB', $bytes / 1024);
        }

        return sprintf('%.1fMB', $bytes / (1024 * 1024));
    }

    private static function untruncated(
        string $content,
        int $totalLines,
        int $totalBytes,
        int $maxLines,
        int $maxBytes,
    ): TruncationResult {
        return new TruncationResult(
            content: $content,
            truncated: false,
            truncatedBy: null,
            totalLines: $totalLines,
            totalBytes: $totalBytes,
            outputLines: $totalLines,
            outputBytes: $totalBytes,
            lastLinePartial: false,
            firstLineExceedsLimit: false,
            maxLines: $maxLines,
            maxBytes: $maxBytes,
        );
    }

    /**
     * The last $maxBytes bytes of a string, cut on a UTF-8 character boundary.
     */
    private static function tailBytes(string $text, int $maxBytes): string
    {
        $length = strlen($text);

        if ($length <= $maxBytes) {
            return $text;
        }

        $start = $length - $maxBytes;

        while ($start < $length && (ord($text[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return substr($text, $start);
    }
}
