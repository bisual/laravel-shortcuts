<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:shortcuts-dto')]
final class MakeDtoCommand extends GeneratorCommand
{
    protected $name = 'make:shortcuts-dto';

    protected $description = 'Create a new DTO class';

    protected $type = 'DTO';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/dto.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\DTOs';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the DTO already exists'],
        ];
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => ['What should the DTO be named?', 'E.g. UserData'],
        ];
    }

    private function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(mb_trim($stub, '/'));

        return $this->files->exists($customPath)
            ? $customPath
            : __DIR__.'/..'.$stub;
    }
}
