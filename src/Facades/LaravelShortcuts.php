<?php

namespace Bisual\LaravelShortcuts\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Bisual\LaravelShortcuts\LaravelShortcuts
 */
class LaravelShortcuts extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Bisual\LaravelShortcuts\LaravelShortcuts::class;
    }
}
