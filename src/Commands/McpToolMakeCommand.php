<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:mcp-tool')]
class McpToolMakeCommand extends PackageGeneratorCommand
{
    protected $name = 'make:mcp-tool';

    protected $description = 'Create a standalone Laravel MCP Tool class';

    protected $type = 'MCP tool';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/mcp-tool.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Mcp\Tools';
    }

    protected function getNameInput(): string
    {
        return $this->qualifyNameWithSuffix(parent::getNameInput(), 'Tool');
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $description = $this->option('description');

        if (! is_string($description) || $description === '') {
            $description = Str::headline(class_basename($name));
        }

        return str_replace('{{ description }}', $description, $stub);
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
            ['description', 'd', InputOption::VALUE_REQUIRED, 'Tool #[Description] text'],
        ];
    }
}
