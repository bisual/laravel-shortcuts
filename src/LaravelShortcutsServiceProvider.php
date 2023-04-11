<?php

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Commands\LaravelShortcutsCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelShortcutsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-shortcuts')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel-shortcuts_table')
            ->hasCommand(LaravelShortcutsCommand::class);
    }
}
