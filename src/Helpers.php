<?php

namespace Bisual\LaravelShortcuts;

class Helper
{
    public static function replaceTextVariablesByRegex(string $template, string $pattern, string $model)
    {
        preg_match_all($pattern, $template, $values);
        $variables_to_replace = $values[1];

        return preg_replace_callback($pattern, function ($matches) use ($variables_to_replace, $model) {
            $variable = $matches[1];
            if (in_array($variable, $variables_to_replace)) {
                try {
                    $s = $model::show($variable)->value;
                } catch (\Exception $e) {
                    $s = '';
                }

                return $s;
            }

            return $matches[0];
        }, $template);

        return $template;
    }
}
