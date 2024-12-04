<?php

namespace Bisual\LaravelShortcuts;

class ControllerValidationHelper
{
    private static $defaultValidationParameters = [
        'search' => 'string|nullable',
        'with' => 'string',
        'without' => 'string',
        'append' => 'string',
        'order_by' => 'string|nullable',
        'order_by_direction' => 'string|nullable',
        'per_page' => 'integer|nullable',
        'page' => 'integer|nullable',
        'scopes' => 'string'
    ];

    public static function indexQueryParametersValidation(array $params = [])
    {
        return array_merge(self::$defaultValidationParameters, $params);
    }
}
