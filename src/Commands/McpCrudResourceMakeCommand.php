<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:mcp-crud-resource')]
class McpCrudResourceMakeCommand extends PackageGeneratorCommand
{
    protected $name = 'make:mcp-crud-resource';

    protected $description = 'Create a new CrudMcpResource class for Laravel MCP';

    protected $type = 'MCP CRUD resource';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/mcp-crud-resource.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Mcp\Cruds';
    }

    protected function getNameInput(): string
    {
        return $this->qualifyNameWithSuffix(
            $this->stripSuffixes(parent::getNameInput(), ['CrudMcp', 'Mcp', 'Crud']),
            'CrudMcp'
        );
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $model = $this->modelOption() ?? $this->guessModelFromName($name);
        $model = $this->qualifyModel($model);
        $repository = $this->repositoryOption() ?? $this->rootNamespace().'Repositories\\'.class_basename($model).'Repository';

        return str_replace(
            [
                '{{ namespacedModel }}',
                '{{ namespacedRepository }}',
                '{{ model }}',
                '{{ repository }}',
            ],
            [
                $model,
                $repository,
                class_basename($model),
                class_basename($repository),
            ],
            $stub
        );
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The model that the MCP CRUD resource applies to'],
            ['repository', 'r', InputOption::VALUE_REQUIRED, 'The repository class (FQCN or basename)'],
        ];
    }

    protected function modelOption(): ?string
    {
        $model = $this->option('model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    protected function repositoryOption(): ?string
    {
        $repository = $this->option('repository');

        if (! is_string($repository) || $repository === '') {
            return null;
        }

        $repository = str_replace('/', '\\', ltrim($repository, '\\/'));

        if (Str::startsWith($repository, $this->rootNamespace())) {
            return $repository;
        }

        if (! Str::endsWith($repository, 'Repository')) {
            $repository .= 'Repository';
        }

        return $this->rootNamespace().'Repositories\\'.class_basename($repository);
    }

    protected function guessModelFromName(string $name): string
    {
        $basename = class_basename($name);
        $basename = (string) Str::replaceEnd('CrudMcp', '', $basename);

        return $basename !== '' ? $basename : 'Model';
    }
}
