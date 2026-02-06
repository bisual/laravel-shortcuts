<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:repository')]
final class MakeRepositoryCommand extends GeneratorCommand
{
    protected $name = 'make:repository';

    protected $description = 'Create a new repository class (optionally extending CrudRepository with --model)';

    protected $type = 'Repository';

    public function handle(): ?bool
    {
        if ($this->option('model') && ! class_exists($this->qualifyModel($this->option('model')))) {
            $this->components->error('The model class ['.$this->option('model').'] does not exist.');

            return false;
        }

        return parent::handle();
    }

    protected function getStub(): string
    {
        $stub = $this->option('model')
            ? '/stubs/repository.stub'
            : '/stubs/repository.plain.stub';

        return $this->resolveStubPath($stub);
    }

    protected function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(mb_trim($stub, '/'));

        return $this->files->exists($customPath)
            ? $customPath
            : __DIR__.'/..'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Repositories';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        if (! $this->option('model')) {
            return $stub;
        }

        $model = $this->qualifyModel($this->option('model'));

        return Str::of($stub)
            ->replace('DummyNamespacedModel', $model)
            ->replace('DummyModel', class_basename($model))
            ->toString();
    }

    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model class (omit for a plain repository without CrudRepository)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the repository already exists'],
        ];
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => ['What should the repository be named?', 'E.g. UserRepository'],
        ];
    }
}
