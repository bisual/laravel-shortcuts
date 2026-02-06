<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
#[AsCommand(name: 'make:shortcuts-request-dto')]
final class MakeRequestDtoCommand extends Command
{
    protected $signature = 'make:shortcuts-request-dto
                            {name : The base name (e.g. RegisterUser creates RegisterUserDto and RegisterUserRequest)}
                            {--force : Overwrite existing files}';

    protected $description = 'Create a DTO and a Form Request that uses it';

    public function handle(Filesystem $files): int
    {
        $baseName = $this->argument('name');
        $dtoClass = $baseName.'Dto';
        $requestClass = $baseName.'Request';

        $rootNamespace = rtrim($this->laravel->getNamespace() ?? config('app.namespace', 'App'), '\\');
        $dtoNamespace = $rootNamespace.'\\DTOs';
        $requestNamespace = $rootNamespace.'\\Http\\Requests';
        $dtoFqcn = $dtoNamespace.'\\'.$dtoClass;

        $dtoPath = $this->laravel->path('DTOs/'.$dtoClass.'.php');
        $requestPath = $this->laravel->path('Http/Requests/'.$requestClass.'.php');

        if (! $this->option('force')) {
            if ($files->exists($dtoPath)) {
                $this->components->error("DTO already exists: {$dtoPath}");

                return self::FAILURE;
            }
            if ($files->exists($requestPath)) {
                $this->components->error("Request already exists: {$requestPath}");

                return self::FAILURE;
            }
        }

        $dtoStub = $this->resolveStubPath('/stubs/request-dto-dto.stub');
        $requestStub = $this->resolveStubPath('/stubs/request-dto-request.stub');

        $dtoContent = str_replace(
            ['DummyNamespace', 'DummyClass'],
            [$dtoNamespace, $dtoClass],
            $files->get($dtoStub)
        );

        $requestContent = str_replace(
            ['DummyNamespace', 'DummyClass', 'DummyDtoFqcn', 'DummyDtoClass'],
            [$requestNamespace, $requestClass, $dtoFqcn, $dtoClass],
            $files->get($requestStub)
        );

        $files->ensureDirectoryExists(\dirname($dtoPath));
        $files->ensureDirectoryExists(\dirname($requestPath));
        $files->put($dtoPath, $dtoContent);
        $files->put($requestPath, $requestContent);

        $this->components->info("Created DTO: {$dtoFqcn}");
        $this->components->info("Created Request: {$requestNamespace}\\{$requestClass}");

        return self::SUCCESS;
    }

    private function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(mb_trim($stub, '/'));

        return $this->laravel->make(Filesystem::class)->exists($customPath)
            ? $customPath
            : __DIR__.'/..'.$stub;
    }
}
