<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Commands\BisualResourceMakeCommand;
use Bisual\LaravelShortcuts\Commands\DtoMakeCommand;
use Bisual\LaravelShortcuts\Commands\McpCrudResourceMakeCommand;
use Bisual\LaravelShortcuts\Commands\McpToolMakeCommand;
use Bisual\LaravelShortcuts\Commands\RepositoryMakeCommand;
use Bisual\LaravelShortcuts\Commands\RequestDtoMakeCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelShortcutsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-shortcuts')
            ->hasConfigFile('shortcuts')
            ->hasCommands(
                RepositoryMakeCommand::class,
                DtoMakeCommand::class,
                RequestDtoMakeCommand::class,
                BisualResourceMakeCommand::class,
                McpCrudResourceMakeCommand::class,
                McpToolMakeCommand::class,
            );
    }
}
