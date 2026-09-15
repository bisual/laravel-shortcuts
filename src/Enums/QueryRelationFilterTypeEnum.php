<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Enums;

enum QueryRelationFilterTypeEnum: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Both = 'both';
}
