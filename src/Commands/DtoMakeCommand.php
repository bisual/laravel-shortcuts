<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:dto')]
class DtoMakeCommand extends PackageGeneratorCommand
{
    protected $name = 'make:dto';

    protected $description = 'Create a new DTO class';

    protected $type = 'DTO';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/dto.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\DTOs';
    }

    protected function getNameInput(): string
    {
        $name = str_replace('/', '\\', parent::getNameInput());
        $basename = class_basename($name);

        if (Str::endsWith($basename, 'Dto') && ! Str::endsWith($basename, 'DTO')) {
            $basename = Str::replaceEnd('Dto', 'DTO', $basename);
            $name = Str::contains($name, '\\')
                ? Str::beforeLast($name, '\\').'\\'.$basename
                : $basename;
        }

        return $this->qualifyNameWithSuffix($name, 'DTO');
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the DTO already exists'],
        ];
    }
}
