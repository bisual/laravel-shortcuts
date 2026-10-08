<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\DTOs;

final class ShortcutsConfigDTO
{
    public function __construct(
        public readonly bool $is_logging_enabled,
    ) {}

    public function toArray(): array
    {
        return [
            'is_logging_enabled' => $this->is_logging_enabled,
        ];
    }
}
