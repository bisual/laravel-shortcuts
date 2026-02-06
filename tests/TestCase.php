<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Tests;

use Bisual\LaravelShortcuts\LaravelShortcutsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

final class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            return 'Bisual\\LaravelShortcuts\\Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelShortcutsServiceProvider::class,
        ];
    }
}
