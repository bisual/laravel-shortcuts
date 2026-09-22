<?php

declare(strict_types=1);

use Bisual\LaravelShortcuts\CrudRepository;
use Bisual\LaravelShortcuts\Mcp\CrudMcpActionTool;
use Bisual\LaravelShortcuts\Mcp\CrudMcpResource;
use Bisual\LaravelShortcuts\Mcp\CrudQueryGuideTool;
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

it('builds mcp tools from the resource including query guide once', function (): void {
    $tools = FakeCrudMcpResource::tools();

    expect($tools)->toHaveCount(3)
        ->and($tools[0])->toBe(CrudQueryGuideTool::class)
        ->and($tools[1])->toBeInstanceOf(CrudMcpActionTool::class)
        ->and($tools[1]->name())->toBe('fake-model-index')
        ->and($tools[2]->name())->toBe('fake-model-show');
});

it('toolsFrom registers the query guide only once for multiple resources', function (): void {
    $tools = CrudMcpResource::toolsFrom([
        FakeCrudMcpResource::class,
        FakeCrudMcpResource::class,
    ]);

    $guide_count = collect($tools)
        ->filter(fn (mixed $tool): bool => $tool === CrudQueryGuideTool::class)
        ->count();

    expect($guide_count)->toBe(1)
        ->and($tools)->toHaveCount(5);
});

it('documents catalog params on the index tool schema', function (): void {
    $tool = new CrudMcpActionTool(FakeCrudMcpResource::class, 'index');

    $schema = JsonSchemaFactory::object(
        fn (JsonSchema $schema): array => $tool->schema($schema),
    )->toArray();

    expect($schema['properties'])->toHaveKeys(['search', 'with', 'order_by', 'page', 'per_page', 'scopes', 'limit']);
});
