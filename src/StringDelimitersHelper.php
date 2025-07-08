<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Exception;

class StringDelimitersHelper {
    private static array $delimiter_ranges = [];

    /**
     * ACCESSORS.
     */
    private static function setDelimiterRanges(string $input, string $start = '<{', string $end = '}>'): void {
        self::$delimiter_ranges = self::getCustomDelimiterRanges($input, $start, $end);
    }

    public static function getDelimiterRanges(): array {
        return self::$delimiter_ranges;
    }

    /**
     * Sets delimiter ranges from the input and then performs a smart explode.
     */
    public static function explodeOutsideRanges( string $separator, string $input) {
        self::setDelimiterRanges($input);
        return self::smartExplode($separator, $input);
    }

    /**
     * Explode by custom separator checking to not separate if $separator is inside precomputed ranges.
     */
    private static function smartExplode(string $separator, string $input): array {
        if (empty(self::$delimiter_ranges)) {
            throw new Exception('Delimiter ranges must be set before calling smartExplode');
        }

        $segments = [];
        $buffer = '';
        $separator_length = strlen($separator);
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if (substr($input, $i, $separator_length) === $separator && !self::isInsidePrecomputedRanges($i)) {
                $segments[] = trim($buffer);
                $buffer = '';
                $i += $separator_length - 1;
            } else {
                $buffer .= $char;
            }
        }

        if (strlen($buffer)) {
            $segments[] = trim($buffer);
        }

        return $segments;
    }

    /**
     * Check if position is inside ranges.
     */
    private static function isInsidePrecomputedRanges(int $position): bool {
        foreach (self::$delimiter_ranges as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the position of the opening and closing dilimiter ranges of string.
     */
    private static function getCustomDelimiterRanges(string $input, string $start, string $end): array {
        $ranges = [];
        $offset = 0;

        while (($start_pos = strpos($input, $start, $offset)) !== false) {
            $end_pos = strpos($input, $end, $start_pos + strlen($start));
            if ($end_pos === false) {
                throw new Exception("Missing closing delimiter '{$end}' on column {$start_pos} of {$input}");
            }

            $ranges[] = [$start_pos, $end_pos + strlen($end)]; // save $start_pos and $end_pos finded
            $offset = $end_pos + strlen($end);
        }

        return $ranges;
    }
}
