<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Bash;

use NeuronCodingTools\Tool\Support\Truncation;
use RuntimeException;

use function array_shift;
use function bin2hex;
use function count;
use function fclose;
use function fopen;
use function fwrite;
use function implode;
use function random_bytes;
use function strlen;
use function strrpos;
use function substr_count;
use function sys_get_temp_dir;

/**
 * Collects a command's output as it arrives, the way pi's bash tool does.
 *
 * A rolling buffer keeps the most recent chunks, at most twice the byte
 * limit, which is all the model can ever be shown. As soon as the output
 * grows past the limit, a temporary file is opened and receives every chunk,
 * including the ones buffered so far, so it ends up holding the full output.
 * The file can also be opened at the end, when the final truncation is by
 * lines rather than bytes.
 *
 * The totals are counted as the chunks stream through, so they describe
 * the whole output even once the buffer has dropped its beginning.
 *
 * @internal
 */
final class OutputCapture
{
    private const string TEMP_FILE_PREFIX = 'solaro-bash-';

    /** @var list<string> */
    private array $chunks = [];
    private int $bufferedBytes = 0;
    private int $totalBytes = 0;
    private int $newlines = 0;
    private int $lastLineBytes = 0;
    private ?string $tempFilePath = null;

    /** @var resource|null */
    private $tempFile = null;

    public function __construct(
        private readonly int $maxBytes = Truncation::DEFAULT_MAX_BYTES,
    ) {}

    public function append(string $chunk): void
    {
        $this->totalBytes += strlen($chunk);
        $this->newlines += substr_count($chunk, "\n");
        $lastNewline = strrpos($chunk, "\n");
        $this->lastLineBytes = $lastNewline === false
            ? $this->lastLineBytes + strlen($chunk)
            : strlen($chunk) - $lastNewline - 1;

        if ($this->tempFile === null && $this->totalBytes > $this->maxBytes) {
            $this->tempFilePath = $this->openTempFile();
        }

        if ($this->tempFile !== null) {
            fwrite($this->tempFile, $chunk);
        }

        $this->chunks[] = $chunk;
        $this->bufferedBytes += strlen($chunk);

        while ($this->bufferedBytes > $this->maxBytes * 2 && count($this->chunks) > 1) {
            $dropped = array_shift($this->chunks);
            $this->bufferedBytes -= strlen($dropped);
        }
    }

    /**
     * The buffered tail of the output.
     */
    public function text(): string
    {
        return implode('', $this->chunks);
    }

    /**
     * The size of the whole output, buffered or not.
     */
    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    /**
     * The line count of the whole output as pi counts it: one more than
     * its newlines, so a final newline is followed by an empty line.
     */
    public function totalLines(): int
    {
        return $this->newlines + 1;
    }

    /**
     * The size of the last line of the whole output, the bytes after its
     * final newline.
     */
    public function lastLineBytes(): int
    {
        return $this->lastLineBytes;
    }

    /**
     * Opens the temporary file if it is not open yet and returns its path.
     */
    public function ensureTempFile(): string
    {
        return $this->tempFilePath ??= $this->openTempFile();
    }

    public function close(): void
    {
        if ($this->tempFile !== null) {
            fclose($this->tempFile);
            $this->tempFile = null;
        }
    }

    /**
     * Opens the temporary file, writes the buffered output into it and
     * returns its path.
     */
    private function openTempFile(): string
    {
        $path = sys_get_temp_dir() . '/' . self::TEMP_FILE_PREFIX . bin2hex(random_bytes(8)) . '.log';
        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new RuntimeException("Cannot create temporary file: {$path}");
        }

        foreach ($this->chunks as $chunk) {
            fwrite($file, $chunk);
        }

        $this->tempFile = $file;

        return $path;
    }
}
