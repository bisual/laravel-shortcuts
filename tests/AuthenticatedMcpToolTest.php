<?php

declare(strict_types=1);

use Bisual\LaravelShortcuts\Mcp\AuthenticatedMcpTool;
use Bisual\LaravelShortcuts\Mcp\CrudMcpActionTool;
use Bisual\LaravelShortcuts\Mcp\ModelMcpQueryGuide;
use Bisual\LaravelShortcuts\Tests\Fixtures\FakeCrudMcpResource;
use Bisual\LaravelShortcuts\Tests\Fixtures\FakeModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;

it('documents model catalog without dialect boilerplate', function (): void {
    $guide = ModelMcpQueryGuide::forModel(FakeModel::class, 2);

    expect($guide)
        ->toContain('Catalog for FakeModel')
        ->toContain('crud-query-guide')
        ->toContain('Available relations')
        ->toContain('Available appends')
        ->toContain('Local scopes')
        ->not->toContain('WHERE / filter dialect');
});

it('exposes shared dialect via dialectGuide', function (): void {
    $dialect = ModelMcpQueryGuide::dialectGuide();

    expect($dialect)
        ->toContain('WHERE / filter dialect')
        ->toContain('with syntax')
        ->toContain('Navigating relations');
});

it('respects mcp allowlists in the query guide', function (): void {
    $guide = ModelMcpQueryGuide::forModel(
        FakeModel::class,
        relation_depth: 1,
        mcp_with: ['company', 'phases..boards'],
        mcp_scopes: ['isActive'],
        mcp_filterable: ['id', 'title'],
    );

    expect($guide)
        ->toContain('company')
        ->toContain('phases..boards')
        ->toContain('isActive')
        ->toContain('id, title')
        ->toContain('Local scopes: isActive')
        ->toContain('Filterable attributes: id, title');
});

it('enforces mcp allowlists on the resource', function (): void {
    FakeCrudMcpResource::$mcp_with = ['company'];
    FakeCrudMcpResource::$mcp_scopes = ['isActive'];
    FakeCrudMcpResource::$mcp_filterable = ['id', 'title'];

    $params = ['with' => 'company', 'scopes' => 'isActive', 'title' => 'x'];
    FakeCrudMcpResource::enforceMcpQueryAllowlists($params);

    $params = ['with' => 'secrets'];

    expect(fn () => FakeCrudMcpResource::enforceMcpQueryAllowlists($params))
        ->toThrow(ValidationException::class);

    FakeCrudMcpResource::$mcp_with = null;
    FakeCrudMcpResource::$mcp_scopes = null;
    FakeCrudMcpResource::$mcp_filterable = null;
});

it('enriches crud index tool description with model catalog', function (): void {
    $description = FakeCrudMcpResource::toolDescriptionFor('index');

    expect($description)
        ->toContain('Catalog for FakeModel')
        ->toContain('crud-query-guide')
        ->not->toContain('WHERE / filter dialect');
});

it('exposes filterable attributes on index schema', function (): void {
    $tool = new CrudMcpActionTool(FakeCrudMcpResource::class, 'index');

    $schema = JsonSchemaFactory::object(
        fn (JsonSchema $schema): array => $tool->schema($schema),
    )->toArray();

    expect($schema['properties'])->toHaveKeys(['search', 'with', 'order_by', 'page', 'per_page', 'scopes', 'limit'])
        ->and($schema['properties']['with']['description'])->toContain('crud-query-guide');
});

it('authenticated mcp tool is abstract with handle pipeline', function (): void {
    $tool = new class extends AuthenticatedMcpTool
    {
        protected ?int $rateLimitMaxAttempts = null;

        public function schema(JsonSchema $schema): array
        {
            return [
                'q' => $schema->string()->required(),
            ];
        }

        protected function rules(): array
        {
            return ['q' => 'required|string'];
        }

        protected function run(McpRequest $request, Authenticatable $user, array $validated): mixed
        {
            return Response::json(['q' => $validated['q']]);
        }
    };

    expect($tool)->toBeInstanceOf(AuthenticatedMcpTool::class);
});
