<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Enums;

enum FilterType: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Both = 'both';

    public function isParentOrBoth(): bool
    {
        return $this === self::Parent || $this === self::Both;
    }
}
