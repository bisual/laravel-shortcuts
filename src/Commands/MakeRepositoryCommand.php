<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:repository')]
final class MakeRepositoryCommand extends GeneratorCommand
{
    protected $name = 'make:repository';

    protected $aliases = ['make:repo'];

    protected $description = 'Create a new repository class (optionally extending CrudRepository with --model)';

    protected $type = 'Repository';

    public function handle(): ?bool
    {
        $name = $this->argument('name');

        if ($name === null) {
            $modelOptionList = $this->getModelOptionList();
            $model = suggest(
                label: 'What model should the repository use?',
                options: function (string $value): array {
                    if ($value === '') {
                        return $modelOptionList;
                    }
                    $filtered = array_values(array_filter($modelOptionList, fn (string $option): bool => str_contains(strtolower($option), strtolower($value))));
                    return $filtered !== [] ? $filtered : ['(none — plain repository)'];
                },
                placeholder: 'Type to search…',
                required: false
            );
            $model = $model === '(none — plain repository)' ? '' : $model;
            if ($model !== '') {
                $name = text(
                    label: 'Repository name?',
                    default: class_basename($this->qualifyModel($model)).'Repository'
                );
            } else {
                $name = text(
                    label: 'What should the repository be named?',
                    placeholder: 'E.g. UserRepository'
                );
            }
            $this->input->setArgument('name', $name);
            $this->input->setOption('model', $model !== '' ? $model : null);
        }

        if ($this->option('model') && $this->option('model') !== '' && ! class_exists($this->qualifyModel($this->option('model')))) {
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
            ->replace('PlaceholderNamespacedModel', $model)
            ->replace('PlaceholderModel', class_basename($model))
            ->toString();
    }

    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::OPTIONAL, 'The name of the repository (prompted if not provided)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['model', null, InputOption::VALUE_OPTIONAL, 'The model class (set interactively)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the repository already exists'],
        ];
    }

    /**
     * @return array<int, string> Flat list for suggest(): first is "(none…)", then FQCNs
     */
    private function getModelOptionList(): array
    {
        $rootNamespace = rtrim($this->laravel->getNamespace() ?? config('app.namespace', 'App'), '\\');
        $modelsNamespace = $rootNamespace.'\\Models';
        $modelsPath = $this->laravel->path('Models');

        $list = ['(none — plain repository)'];

        if (! $this->files->isDirectory($modelsPath)) {
            return $list;
        }

        $fqcns = [];
        foreach ($this->files->files($modelsPath) as $file) {
            $basename = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            if ($basename === '') {
                continue;
            }
            $fqcn = $modelsNamespace.'\\'.$basename;
            if (class_exists($fqcn)) {
                $fqcns[] = $fqcn;
            }
        }
        sort($fqcns, SORT_STRING);

        return array_merge($list, $fqcns);
    }

    private function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(mb_trim($stub, '/'));

        return $this->files->exists($customPath)
            ? $customPath
            : __DIR__.'/..'.$stub;
    }
}
