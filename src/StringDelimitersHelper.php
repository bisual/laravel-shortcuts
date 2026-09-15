<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Exception;

final class StringDelimitersHelper
{
    /**
     * Default pairs for explode: values and operators (so commas in [date,>=] are kept).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const DEFAULT_EXPLODE_PAIRS = [
        ['<{', '}>'],
        ['[', ']'],
    ];

    /**
     * Default pairs for indexOf: only values, so operator [ ] remain findable.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const DEFAULT_INDEX_PAIRS = [
        ['<{', '}>'],
    ];

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
     *
     * @param  array<int, array{0: string, 1: string}>|null  $delimiter_pairs
     */
    public static function explodeOutsideRanges(string $separator, string $input, ?array $delimiter_pairs = null): array
    {
        self::setDelimiterRanges($input, $delimiter_pairs ?? self::DEFAULT_EXPLODE_PAIRS);

        return self::smartExplode($separator, $input);
    }

    /**
     * Get the position of $needle in $input ignoring occurrences inside protected ranges.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $delimiter_pairs
     */
    public static function indexOfOutsideRanges(string $needle, string $input, int $offset = 0, ?array $delimiter_pairs = null): int|false
    {
        self::setDelimiterRanges($input, $delimiter_pairs ?? self::DEFAULT_INDEX_PAIRS);

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

    /**
     * @param  array<int, array{0: string, 1: string}>  $delimiter_pairs
     */
    private static function setDelimiterRanges(string $input, array $delimiter_pairs): void
    {
        $ranges = [];

        foreach ($delimiter_pairs as [$start, $end]) {
            $ranges = [
                ...$ranges,
                ...self::getCustomDelimiterRanges($input, $start, $end, $ranges),
            ];
        }

        self::$delimiter_ranges = $ranges;
    }

    /**
     * Get the position of the opening and closing delimiter ranges of string.
     * Openings that fall inside already known ranges (e.g. [ inside <{ }>) are skipped.
     *
     * @param  array<int, array{0: int, 1: int}>  $existing_ranges
     * @return array<int, array{0: int, 1: int}>
     */
    private static function getCustomDelimiterRanges(string $input, string $start, string $end, array $existing_ranges = []): array
    {
        $ranges = [];
        $offset = 0;
        $start_length = mb_strlen($start);
        $end_length = mb_strlen($end);

        while (($start_pos = mb_strpos($input, $start, $offset)) !== false) {
            if (self::isInsideRanges($start_pos, $existing_ranges) || self::isInsideRanges($start_pos, $ranges)) {
                $offset = $start_pos + $start_length;

                continue;
            }

            $end_pos = mb_strpos($input, $end, $start_pos + $start_length);
            if ($end_pos === false) {
                throw new Exception("Missing closing delimiter '{$end}' on column {$start_pos} of {$input}");
            }

            $ranges[] = [$start_pos, $end_pos + $end_length];
            $offset = $end_pos + $end_length;
        }

        return $ranges;
    }

    /**
     * Explode by custom separator checking to not separate if $separator is inside precomputed ranges.
     */
    private static function smartExplode(string $separator, string $input): array
    {
        $segments = [];
        $buffer = '';
        $separator_length = mb_strlen($separator);
        $length = mb_strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($input, $i, 1);

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
        return self::isInsideRanges($position, self::$delimiter_ranges);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $ranges
     */
    private static function isInsideRanges(int $position, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }
}
