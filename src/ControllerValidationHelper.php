<?php

namespace Bisual\LaravelShortcuts;

class ControllerValidationHelper {

    private static $defaultValidationParameters = [
        'with' => 'string',
        'without' => 'string',
    ];

    public static function indexQueryParametersValidation(array $params = []) {
        return array_merge($params, self::$defaultValidationParameters);
    }

}
