<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:repository')]
class RepositoryMakeCommand extends PackageGeneratorCommand
{
    protected $name = 'make:repository';

    protected $description = 'Create a new repository class';

    protected $type = 'Repository';

    protected function getStub(): string
    {
        return $this->modelOption()
            ? $this->resolveStubPath('/stubs/repository.crud.stub')
            : $this->resolveStubPath('/stubs/repository.plain.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Repositories';
    }

    protected function getNameInput(): string
    {
        return $this->qualifyNameWithSuffix(parent::getNameInput(), 'Repository');
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $model = $this->modelOption();

        if ($model === null) {
            return $stub;
        }

        $model = $this->qualifyModel($model);

        return str_replace(
            ['{{ namespacedModel }}', '{{ namespacedmodel }}', '{{ model }}'],
            [$model, $model, class_basename($model)],
            $stub
        );
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the repository already exists'],
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The model that the repository applies to'],
        ];
    }

    protected function modelOption(): ?string
    {
        $model = $this->option('model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
