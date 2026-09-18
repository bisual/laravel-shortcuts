<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:request-dto')]
class RequestDtoMakeCommand extends PackageGeneratorCommand
{
    protected $name = 'make:request-dto';

    protected $description = 'Create a new form request class with a matching DTO';

    protected $type = 'Request';

    public function handle(): ?bool
    {
        $this->call('make:dto', array_filter([
            'name' => $this->dtoNameInput(),
            '--force' => $this->option('force') ?: null,
        ]));

        return parent::handle();
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/request-dto.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Http\Requests';
    }

    protected function getNameInput(): string
    {
        return $this->qualifyNameWithSuffix($this->baseNameInput(), 'Request');
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $dto = $this->qualifyDtoClass();

        return str_replace(
            ['{{ namespacedDto }}', '{{ namespaceddto }}', '{{ dto }}'],
            [$dto, $dto, class_basename($dto)],
            $stub
        );
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the request already exists'],
        ];
    }

    protected function dtoNameInput(): string
    {
        return $this->qualifyNameWithSuffix($this->baseNameInput(), 'DTO');
    }

    protected function qualifyDtoClass(): string
    {
        return $this->rootNamespace().'DTOs\\'.$this->dtoNameInput();
    }

    protected function baseNameInput(): string
    {
        return $this->stripSuffixes(parent::getNameInput(), ['Request', 'DTO', 'Dto']);
    }
}
