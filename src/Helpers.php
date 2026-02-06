<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Stringable;

final class Helper
{
    public static function replaceTextVariablesByRegexUsingStr(string|Stringable $template, string $pattern, string $repository): string|Stringable
    {
        return str($template)
            ->replaceMatches($pattern, replace: function (array $matches) use ($repository): string {
                $variable = $matches[1];

                try {
                    return $repository::show($variable)->value;
                } catch (ModelNotFoundException) {
                    return '';
                }
            })
            ->when(is_string($template), fn (Stringable $str): string => $str->toString());
    }
}
