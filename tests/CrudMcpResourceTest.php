<?php

declare(strict_types=1);

use Bisual\LaravelShortcuts\CrudRepository;
use Bisual\LaravelShortcuts\Mcp\CrudMcpActionTool;
use Bisual\LaravelShortcuts\Tests\Fixtures\FakeCrudMcpResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;

it('lists reserved index parameters with validation rules', function (): void {
    $rules = CrudRepository::indexValidationRules();

    expect($rules)->toHaveKeys(['search', 'with', 'without', 'append', 'order_by', 'page', 'per_page', 'limit', 'scopes', 'select'])
        ->and($rules)->not->toHaveKey('id')
        ->and(CrudRepository::parameterDefinitions())->toHaveKey('with')
        ->and(CrudRepository::parameterDefinitionsForAction('show'))->toHaveKey('id');
});

it('respects only when building enabled actions', function (): void {
    expect(FakeCrudMcpResource::enabledActions())->toBe(['index', 'show'])
        ->and(FakeCrudMcpResource::toolNameFor('index'))->toBe('fake-model-index')
        ->and(FakeCrudMcpResource::abilityFor('index'))->toBe('index')
        ->and(FakeCrudMcpResource::abilityFor('show'))->toBe('view');
});

it('builds mcp tools from the resource', function (): void {
    $tools = FakeCrudMcpResource::tools();

    expect($tools)->toHaveCount(2)
        ->and($tools[0])->toBeInstanceOf(CrudMcpActionTool::class)
        ->and($tools[0]->name())->toBe('fake-model-index')
        ->and($tools[1]->name())->toBe('fake-model-show');
});

it('documents catalog params on the index tool schema', function (): void {
    $tool = new CrudMcpActionTool(FakeCrudMcpResource::class, 'index');

    $schema = JsonSchemaFactory::object(
        fn (JsonSchema $schema): array => $tool->schema($schema),
    )->toArray();

    expect($schema['properties'])->toHaveKeys(['search', 'with', 'order_by', 'page', 'per_page', 'scopes', 'limit']);
});
