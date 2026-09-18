<?php

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Commands\BisualResourceMakeCommand;
use Bisual\LaravelShortcuts\Commands\DtoMakeCommand;
use Bisual\LaravelShortcuts\Commands\RepositoryMakeCommand;
use Bisual\LaravelShortcuts\Commands\RequestDtoMakeCommand;
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
            ->hasCommands(
                RepositoryMakeCommand::class,
                DtoMakeCommand::class,
                RequestDtoMakeCommand::class,
                BisualResourceMakeCommand::class,
            );
    }
}
