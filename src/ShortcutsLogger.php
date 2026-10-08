<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

final class ShortcutsLogger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $is_logging_enabled = config('shortcuts.is_logging_enabled', false) !== false;

        if ($is_logging_enabled) {
            logger()->log($level, $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }
}
