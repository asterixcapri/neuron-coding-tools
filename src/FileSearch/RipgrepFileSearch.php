<?php

declare(strict_types=1);

namespace NeuronCodingTools\FileSearch;

use Amp\Process\Process;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\splitLines;
use function base64_decode;
use function basename;
use function dirname;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_split;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * File search through ripgrep: `rg --files` run in the searched directory,
 * so its output is already relative, filtered by the glob in PHP; and
 * `rg --json` for content search, its event stream parsed as pi does.
 *
 * The glob is not handed to `rg --glob` on purpose: a ripgrep glob "always
 * overrides any other ignore logic", so `--glob '*.txt'` would list a
 * gitignored `notes.txt` again, while pi's `fd --glob` filters after the
 * ignore rules. Matching the listing with the shared {@see Glob} keeps
 * `.gitignore` respected and both implementations in agreement. The same
 * holds for content search, so the glob filters ripgrep's matches in PHP.
 *
 * `--hidden` includes dotfiles, `--no-require-git` makes `.gitignore` count
 * outside a git repository too (as pi's `fd` call does), and `--glob '!.git'`
 * keeps the repository's own metadata out, which ripgrep would otherwise
 * list under `--hidden`. The process runs through Amp so the terminal keeps
 * animating.
 */
final class RipgrepFileSearch implements FileSearchInterface
{
    public function __construct(
        private readonly string $binary = 'rg',
    ) {}

    public function listFiles(string $directory, string $pattern, int $limit): FileListing
    {
        $regex = Glob::toRegex(Glob::normalize($pattern));

        $process = Process::start(
            [$this->binary, '--files', '--hidden', '--no-require-git', '--color=never', '--glob', '!.git'],
            $directory,
        );
        $process->getStdin()->close();

        $stdout = async(static fn(): string => buffer($process->getStdout()));
        $stderr = async(static fn(): string => buffer($process->getStderr()));
        $exitCode = $process->join();
        $output = $stdout->await();
        $error = trim($stderr->await());

        // Exit code 1 means no file at all. Any other failure with no output
        // is reported with ripgrep's own message; with output (a directory it
        // could not read, say) the partial listing is kept, as pi does.
        if ($exitCode > 1 && $output === '') {
            throw new FileSearchException($error !== '' ? $error : "rg exited with code {$exitCode}");
        }

        $paths = [];

        // Only the line terminator goes: a name may begin or end with spaces.
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = str_starts_with($line, './') ? substr($line, 2) : $line;

            if ($line !== '' && preg_match($regex, $line) === 1) {
                $paths[] = $line;
            }
        }

        return FileListing::fromPaths($paths, $limit);
    }

    /**
     * `rg --json` streams one event per line: `begin` and `end` per file,
     * `match` and `context` per line. `--sort path` makes the order
     * deterministic (the entries of every directory by name) at the price
     * of a single thread. The stream is read until the limit is reached and
     * the last match's context lines have arrived, then ripgrep is killed,
     * as pi does.
     */
    public function searchContent(string $path, string $pattern, ContentSearchOptions $options): ContentSearchResult
    {
        $isDirectory = is_dir($path);
        $glob = $isDirectory && $options->glob !== null ? Glob::toRegex($options->glob) : null;

        $arguments = [$this->binary, '--json', '--line-number', '--color=never', '--hidden', '--no-require-git', '--sort', 'path', '--glob', '!.git'];

        if ($options->ignoreCase) {
            $arguments[] = '--ignore-case';
        }

        if ($options->literal) {
            $arguments[] = '--fixed-strings';
        }

        if ($options->context > 0) {
            $arguments[] = '--context';
            $arguments[] = (string) $options->context;
        }

        $arguments[] = '--regexp';
        $arguments[] = $pattern;
        $arguments[] = $isDirectory ? '.' : basename($path);

        $process = Process::start($arguments, $isDirectory ? $path : dirname($path));
        $process->getStdin()->close();
        $stderr = async(static fn(): string => buffer($process->getStderr()));

        /** @var array<string, array{lines: array<int, string>, matches: list<int>}> $files */
        $files = [];
        $matchCount = 0;
        $limitReached = false;
        $killed = false;
        $lastPath = '';
        $lastLine = 0;

        foreach (splitLines($process->getStdout()) as $line) {
            $event = self::parseEvent($line);

            if ($event === null) {
                continue;
            }

            $filePath = $event->path;
            $lineNumber = $event->lineNumber;
            $text = $event->text;

            if ($limitReached) {
                // Only the rest of the last match's window is still wanted.
                if ($filePath !== $lastPath || $event->type === 'end' || $lineNumber === null || $lineNumber > $lastLine + $options->context) {
                    $process->kill();
                    $killed = true;
                    break;
                }
            }

            if ($lineNumber === null || $text === null || ($glob !== null && preg_match($glob, $filePath) !== 1)) {
                continue;
            }

            $files[$filePath]['lines'][$lineNumber] = $text;
            $files[$filePath]['matches'] ??= [];

            if ($event->type === 'match' && !$limitReached) {
                $files[$filePath]['matches'][] = $lineNumber;
                $matchCount++;

                if ($matchCount >= $options->limit) {
                    $limitReached = true;
                    $lastPath = $filePath;
                    $lastLine = $lineNumber;

                    if ($options->context === 0) {
                        $process->kill();
                        $killed = true;
                        break;
                    }
                }
            }
        }

        $exitCode = $process->join();
        $error = trim($stderr->await());

        // Exit code 1 means no match; a kill for the limit is expected.
        if (!$killed && $exitCode > 1) {
            throw new FileSearchException($error !== '' ? $error : "rg exited with code {$exitCode}");
        }

        $matches = [];

        foreach ($files as $filePath => $file) {
            foreach ($file['matches'] as $lineNumber) {
                $window = [];

                for ($current = $lineNumber - $options->context; $current <= $lineNumber + $options->context; $current++) {
                    if ($current !== $lineNumber && isset($file['lines'][$current])) {
                        $window[$current] = $file['lines'][$current];
                    }
                }

                $matches[] = new ContentMatch($filePath, $lineNumber, $file['lines'][$lineNumber], $window);
            }
        }

        return new ContentSearchResult($matches, $limitReached);
    }

    /**
     * One `rg --json` event as a {@see RipgrepEvent}; anything that is not a
     * file event is null.
     */
    private static function parseEvent(string $line): ?RipgrepEvent
    {
        $event = json_decode($line, true);

        if (!is_array($event) || !isset($event['type'], $event['data']) || !is_string($event['type']) || !is_array($event['data'])) {
            return null;
        }

        $data = $event['data'];
        $filePath = is_array($data['path'] ?? null) ? self::textOf($data['path']) : null;

        if ($filePath === null) {
            return null;
        }

        $filePath = str_starts_with($filePath, './') ? substr($filePath, 2) : $filePath;
        $lineNumber = isset($data['line_number']) && is_int($data['line_number']) ? $data['line_number'] : null;
        $text = is_array($data['lines'] ?? null) ? self::textOf($data['lines']) : null;

        if ($text !== null) {
            $text = str_replace("\r", '', str_ends_with($text, "\n") ? substr($text, 0, -1) : $text);
        }

        return new RipgrepEvent($event['type'], $filePath, $lineNumber, $text);
    }

    /**
     * ripgrep's "arbitrary data" object: `text` when valid UTF-8, otherwise
     * the raw `bytes` in base64.
     *
     * @param array<mixed> $data
     */
    private static function textOf(array $data): ?string
    {
        if (isset($data['text']) && is_string($data['text'])) {
            return $data['text'];
        }

        if (isset($data['bytes']) && is_string($data['bytes'])) {
            $decoded = base64_decode($data['bytes'], true);

            return $decoded === false ? null : $decoded;
        }

        return null;
    }
}
