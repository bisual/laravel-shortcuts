<?php

namespace Bisual\LaravelShortcuts\Tests;

use Bisual\LaravelShortcuts\LaravelShortcutsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            return 'Bisual\\LaravelShortcuts\\Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelShortcutsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
