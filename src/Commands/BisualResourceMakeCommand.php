<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:bisual-resource')]
class BisualResourceMakeCommand extends Command
{
    protected $signature = 'make:bisual-resource
        {name? : The name of the model}
        {--a|all : Generate a controller, repository, factory, seeder, policy, DTOs, and migration}
        {--c|controller : Create a new CRUD API controller}
        {--d|dto : Create Store and Update DTO classes}
        {--f|factory : Create a new factory for the model}
        {--m|migration : Create a new migration file for the model}
        {--p|policy : Create a new policy for the model}
        {--r|repository : Create a new repository for the model}
        {--s|seeder : Create a new seeder for the model}';

    protected $description = 'Create a Bisual model resource with optional CRUD companions';

    public function handle(): int
    {
        if (! $this->askForName()) {
            return self::FAILURE;
        }

        if ($this->option('all')) {
            $this->enableAllComponents();
        } elseif ($this->shouldPromptForComponents()) {
            $this->promptForComponents();
        }

        $this->ensureModelExists();

        if ($this->option('repository')) {
            $this->createRepository();
        }

        if ($this->option('dto') && $this->option('controller')) {
            $this->createRequestDtos();
        } elseif ($this->option('dto')) {
            $this->createDtos();
        }

        if ($this->option('controller')) {
            $this->createController();
        }

        if ($this->option('factory')) {
            $this->createFactory();
        }

        if ($this->option('migration')) {
            $this->createMigration();
        }

        if ($this->option('seeder')) {
            $this->createSeeder();
        }

        if ($this->option('policy')) {
            $this->createPolicy();
        }

        $this->writeShortcutHint();

        return self::SUCCESS;
    }

    protected function askForName(): bool
    {
        $name = $this->argument('name');

        if (is_string($name) && trim($name) !== '') {
            return $this->guardAgainstExistingModel($name);
        }

        $this->input->setArgument('name', text(
            label: 'What is the model name?',
            placeholder: 'Flight',
            required: true,
            validate: function (string $value): ?string {
                if (trim($value) === '') {
                    return 'The name is required.';
                }

                if ($this->modelAlreadyExists($value)) {
                    return "The model [{$value}] already exists.";
                }

                return null;
            },
        ));

        return true;
    }

    protected function guardAgainstExistingModel(string $name): bool
    {
        if (! $this->modelAlreadyExists($name)) {
            return true;
        }

        $this->components->error("The model [{$name}] already exists.");

        return false;
    }

    protected function writeShortcutHint(): void
    {
        $flags = $this->option('all')
            ? 'a'
            : collect([
                'c' => 'controller',
                'r' => 'repository',
                'f' => 'factory',
                's' => 'seeder',
                'p' => 'policy',
                'd' => 'dto',
                'm' => 'migration',
            ])->filter(fn (string $option) => (bool) $this->option($option))->keys()->implode('');

        $command = 'php artisan make:bisual-resource '.$this->modelName();

        if ($flags !== '') {
            $command .= ' -'.$flags;
        }

        $this->newLine();
        $this->comment("🤓 Next time you could do: {$command}");
    }

    protected function enableAllComponents(): void
    {
        foreach (['controller', 'repository', 'factory', 'seeder', 'policy', 'dto', 'migration'] as $option) {
            $this->input->setOption($option, true);
        }
    }

    protected function shouldPromptForComponents(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return ! $this->hasComponentOptions();
    }

    protected function hasComponentOptions(): bool
    {
        foreach (['controller', 'repository', 'factory', 'seeder', 'policy', 'dto', 'migration', 'all'] as $option) {
            if ($this->option($option)) {
                return true;
            }
        }

        return false;
    }

    protected function promptForComponents(): void
    {
        (new Collection(multiselect(
            label: 'Desired components',
            options: [
                'controller' => 'API Controller',
                'repository' => 'Repository',
                'factory' => 'Factory',
                'seeder' => 'Seeder',
                'policy' => 'Policy',
                'dto' => 'DTOs',
                'migration' => 'Migration',
            ],
        )))->each(fn (string $option) => $this->input->setOption($option, true));
    }

    protected function ensureModelExists(): void
    {
        $this->call('make:model', [
            'name' => $this->modelName(),
        ]);
    }

    protected function createRepository(): void
    {
        $this->call('make:repository', [
            'name' => $this->modelBasename(),
            '--model' => $this->qualifyModel($this->modelName()),
        ]);
    }

    protected function createDtos(): void
    {
        foreach ($this->dtoBasenames() as $name) {
            $this->call('make:dto', [
                'name' => $name,
            ]);
        }
    }

    protected function createRequestDtos(): void
    {
        foreach ($this->dtoBasenames() as $name) {
            $this->call('make:request-dto', [
                'name' => $name,
            ]);
        }
    }

    protected function createController(): void
    {
        $class = $this->modelBasename().'Controller';
        $path = app_path('Http/Controllers/API/'.$class.'.php');

        if (file_exists($path)) {
            $this->components->error('Controller already exists.');

            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $stub = file_get_contents($this->stubPath('/stubs/controller.crud.stub'));

        if ($stub === false) {
            $this->components->error('Unable to load the controller stub.');

            return;
        }

        $withRequests = $this->option('controller') && $this->option('dto');
        $model = $this->qualifyModel($this->modelName());
        $repository = $this->repositoryClass();
        $storeRequest = 'Store'.$this->modelBasename().'Request';
        $updateRequest = 'Update'.$this->modelBasename().'Request';

        $requestImports = $withRequests
            ? 'use '.$this->rootNamespace().'Http\\Requests\\'.$storeRequest.";\nuse ".$this->rootNamespace().'Http\\Requests\\'.$updateRequest.";\n"
            : '';

        $stub = str_replace(
            [
                '{{ namespace }}',
                '{{ namespacedModel }}',
                '{{ namespacedRepository }}',
                '{{ requestImports }}',
                '{{ model }}',
                '{{ repository }}',
                '{{ class }}',
                '{{ storeRequestClass }}',
                '{{ updateRequestClass }}',
            ],
            [
                $this->rootNamespace().'Http\\Controllers\\API',
                $model,
                $repository,
                $requestImports,
                class_basename($model),
                class_basename($repository),
                $class,
                $withRequests ? $storeRequest.'::class' : '[]',
                $withRequests ? $updateRequest.'::class' : '[]',
            ],
            $stub
        );

        file_put_contents($path, $stub);

        $this->components->info(sprintf('Controller [%s] created successfully.', $path));
    }

    protected function createFactory(): void
    {
        $this->call('make:factory', [
            'name' => $this->modelBasename().'Factory',
            '--model' => $this->qualifyModel($this->modelName()),
        ]);
    }

    protected function createMigration(): void
    {
        $table = Str::snake(Str::pluralStudly($this->modelBasename()));

        $this->call('make:migration', [
            'name' => "create_{$table}_table",
            '--create' => $table,
        ]);
    }

    protected function createSeeder(): void
    {
        $this->call('make:seeder', [
            'name' => $this->modelBasename().'Seeder',
        ]);
    }

    protected function createPolicy(): void
    {
        $this->call('make:policy', [
            'name' => $this->modelBasename().'Policy',
            '--model' => $this->qualifyModel($this->modelName()),
        ]);
    }

    protected function dtoBasenames(): array
    {
        $model = $this->modelBasename();

        return [
            'Store'.$model,
            'Update'.$model,
        ];
    }

    protected function repositoryClass(): string
    {
        return $this->rootNamespace().'Repositories\\'.$this->modelBasename().'Repository';
    }

    protected function modelPath(?string $name = null): string
    {
        $model = $this->qualifyModel($name ?? $this->modelName());
        $relative = Str::replaceFirst($this->rootNamespace(), '', $model);

        return app_path(str_replace('\\', '/', $relative).'.php');
    }

    protected function modelAlreadyExists(string $name): bool
    {
        return file_exists($this->modelPath($name));
    }

    protected function modelName(): string
    {
        return str_replace('\\', '/', trim((string) $this->argument('name')));
    }

    protected function modelBasename(): string
    {
        return Str::studly(class_basename($this->modelName()));
    }

    protected function qualifyModel(string $model): string
    {
        $model = ltrim($model, '\\/');
        $model = str_replace('/', '\\', $model);
        $rootNamespace = $this->rootNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace.'Models\\'.$model
            : $rootNamespace.$model;
    }

    protected function rootNamespace(): string
    {
        return $this->laravel->getNamespace();
    }

    protected function stubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($customPath)
            ? $customPath
            : dirname(__DIR__, 2).$stub;
    }
}
