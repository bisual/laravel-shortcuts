<?php

declare(strict_types=1);

use Bisual\LaravelShortcuts\DTOs\ShortcutsConfigDTO;

return (new ShortcutsConfigDTO(
    is_logging_enabled: env('SHOULD_LOG_LARAVEL_SHORTCUTS', false) !== false,
))->toArray();
