<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Exception;

final class StringDelimitersHelper
{
    private static array $delimiter_ranges = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static function getDelimiterRanges(): array
    {
        return self::$delimiter_ranges;
    }

    /**
     * Sets delimiter ranges from the input and then performs a smart explode.
     */
    public static function explodeOutsideRanges(string $separator, string $input)
    {
        self::setDelimiterRanges($input);

        return self::smartExplode($separator, $input);
    }

    /**
     * Get the position of $needle in $input ignoring occurrences inside <{...}> delimiters.
     */
    public static function indexOfOutsideRanges(string $needle, string $input, int $offset = 0): int|false
    {
        self::setDelimiterRanges($input);

        $needle_length = mb_strlen($needle);
        $length = mb_strlen($input);

        for ($i = $offset; $i < $length; $i++) {
            if (mb_substr($input, $i, $needle_length) === $needle && ! self::isInsidePrecomputedRanges($i)) {
                return $i;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function setDelimiterRanges(string $input, string $start = '<{', string $end = '}>'): void
    {
        self::$delimiter_ranges = self::getCustomDelimiterRanges($input, $start, $end);
    }

    /**
     * Get the position of the opening and closing delimiter ranges of string.
     */
    private static function getCustomDelimiterRanges(string $input, string $start, string $end): array
    {
        $ranges = [];
        $offset = 0;

        while (($start_pos = mb_strpos($input, $start, $offset)) !== false) {
            $end_pos = mb_strpos($input, $end, $start_pos + mb_strlen($start));
            if ($end_pos === false) {
                throw new Exception("Missing closing delimiter '{$end}' on column {$start_pos} of {$input}");
            }

            $ranges[] = [$start_pos, $end_pos + mb_strlen($end)]; // save $start_pos and $end_pos finded
            $offset = $end_pos + mb_strlen($end);
        }

        return $ranges;
    }

    /**
     * Explode by custom separator checking to not separate if $separator is inside precomputed ranges.
     */
    private static function smartExplode(string $separator, string $input): array
    {
        // Ranges vacíos = no hay <{...}> en el string; igual se puede explotar con normalidad
        $segments = [];
        $buffer = '';
        $separator_length = mb_strlen($separator);
        $length = mb_strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if (mb_substr($input, $i, $separator_length) === $separator && ! self::isInsidePrecomputedRanges($i)) {
                $segments[] = mb_trim($buffer);
                $buffer = '';
                $i += $separator_length - 1;
            } else {
                $buffer .= $char;
            }
        }

        if (mb_strlen($buffer)) {
            $segments[] = mb_trim($buffer);
        }

        return $segments;
    }

    /**
     * Check if position is inside ranges.
     */
    private static function isInsidePrecomputedRanges(int $position): bool
    {
        foreach (self::$delimiter_ranges as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }
}
