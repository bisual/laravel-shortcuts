<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Commands\MakeDtoCommand;
use Bisual\LaravelShortcuts\Commands\MakeRepositoryCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelShortcutsServiceProvider extends PackageServiceProvider
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
            ->hasCommand(MakeDtoCommand::class)
            ->hasCommand(MakeRepositoryCommand::class);
    }
}
