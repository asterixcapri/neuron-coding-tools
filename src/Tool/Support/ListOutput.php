<?php

declare(strict_types=1);

namespace NeuronCodingTools\Tool\Support;

use function implode;

use const PHP_INT_MAX;

/**
 * The result text of a listing Tool (`find`, `grep`, `ls`): the lines cut
 * at {@see Truncation::DEFAULT_MAX_BYTES}, then pi's notes in brackets.
 *
 * The line count is already capped by the Tool's own `limit`, so only the
 * byte limit applies here. The notes come out in pi's order: the Tool's
 * limit note, the byte limit note, and any trailing note.
 */
final class ListOutput
{
    /**
     * @param list<string> $lines
     * @param string|null $limitNotice the Tool's own limit note, when its limit was reached
     * @param string|null $trailingNotice a note printed after the byte limit one
     */
    public static function render(array $lines, ?string $limitNotice = null, ?string $trailingNotice = null): string
    {
        $truncation = Truncation::head(implode("\n", $lines), PHP_INT_MAX);
        $notices = [];

        if ($limitNotice !== null) {
            $notices[] = $limitNotice;
        }

        if ($truncation->truncated) {
            $notices[] = Truncation::formatSize(Truncation::DEFAULT_MAX_BYTES) . ' limit reached';
        }

        if ($trailingNotice !== null) {
            $notices[] = $trailingNotice;
        }

        return $truncation->content . ($notices === [] ? '' : "\n\n[" . implode('. ', $notices) . ']');
    }
}
