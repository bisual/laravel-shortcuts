<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Bisual\LaravelShortcuts\CrudRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Generic MCP tool bound to a {@see CrudMcpResource} action.
 *
 * @internal Prefer registering tools via CrudMcpResource::tools().
 */
final class CrudMcpActionTool extends Tool
{
    /**
     * @param  class-string<CrudMcpResource>  $resource
     */
    public function __construct(
        private readonly string $resource,
        private readonly string $action,
    ) {
        $this->name = $resource::toolNameFor($action);
        $this->title = str($this->name)->headline()->toString();
        $this->description = $resource::toolDescriptionFor($action);
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return match ($this->action) {
            CrudRepository::ACTION_INDEX,
            CrudRepository::ACTION_SHOW => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            CrudRepository::ACTION_DESTROY => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
            CrudRepository::ACTION_STORE => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
            CrudRepository::ACTION_UPDATE => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            default => [],
        };
    }

    public function handle(McpRequest $request): Response|ResponseFactory
    {
        /** @var Authenticatable|null $user */
        $user = $request->user();

        if ($user === null) {
            return Response::error('Unauthenticated.');
        }

        try {
            $result = match ($this->action) {
                CrudRepository::ACTION_INDEX => $this->handleIndex($request, $user),
                CrudRepository::ACTION_SHOW => $this->handleShow($request, $user),
                CrudRepository::ACTION_STORE => $this->handleStore($request, $user),
                CrudRepository::ACTION_UPDATE => $this->handleUpdate($request, $user),
                CrudRepository::ACTION_DESTROY => $this->handleDestroy($request, $user),
                default => throw new \InvalidArgumentException('Unknown CRUD action: '.$this->action),
            };
        } catch (AuthorizationException) {
            return Response::error('Permission denied.');
        } catch (ValidationException $exception) {
            return Response::error(collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.');
        }

        return Response::structured($this->toStructuredArray($result));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (CrudRepository::parameterDefinitionsForAction($this->action) as $name => $definition) {
            $definition = array_merge($definition, $this->resource::$parameterDefinitionOverrides[$name] ?? []);

            $field = match ($definition['type']) {
                'integer' => $schema->integer(),
                default => $schema->string(),
            };

            $field = $field->description($definition['description']);

            if ($definition['required']) {
                $field = $field->required();
            } else {
                $field = $field->nullable();
            }

            $fields[$name] = $field;
        }

        if ($this->action === CrudRepository::ACTION_STORE) {
            $fields = array_merge($fields, $this->schemaFromRules($schema, $this->resource::$storeRequestClass));
        }

        if ($this->action === CrudRepository::ACTION_UPDATE) {
            $fields = array_merge($fields, $this->schemaFromRules($schema, $this->resource::$updateRequestClass));
        }

        if ($this->action === CrudRepository::ACTION_INDEX && is_array($this->resource::$indexQueryValidations)) {
            foreach ($this->resource::$indexQueryValidations as $name => $rules) {
                if (isset($fields[$name])) {
                    continue;
                }

                $fields[$name] = $schema->string()
                    ->description('Extra index filter: '.$name)
                    ->nullable();
            }
        }

        return $fields;
    }

    /**
     * @return LengthAwarePaginator<int, Model>|Collection<int, Model>
     */
    private function handleIndex(McpRequest $request, Authenticatable $user): LengthAwarePaginator|Collection
    {
        $rules = is_array($this->resource::$indexQueryValidations)
            ? CrudRepository::indexValidationRules($this->resource::$indexQueryValidations)
            : CrudRepository::indexValidationRules();

        if (is_string($this->resource::$indexQueryValidations) && is_subclass_of($this->resource::$indexQueryValidations, FormRequest::class)) {
            $params = $this->validateWithFormRequest($this->resource::$indexQueryValidations, $request->all());
        } else {
            $params = Validator::make($request->all(), $rules)->validate();
        }

        $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->resource::prepareIndexParams($params, $user);

        if ($this->resource::shouldAuthorize('index')) {
            Gate::forUser($user)->authorize(
                $this->resource::abilityFor('index'),
                [$this->resource::$model, $params],
            );
        }

        $paginate = isset($params['page']) || isset($params['per_page']);

        /** @var class-string<CrudRepository> $repository */
        $repository = $this->resource::$repository;

        return $repository::index($params, $paginate);
    }

    private function handleShow(McpRequest $request, Authenticatable $user): Model
    {
        $params = Validator::make($request->all(), CrudRepository::showValidationRules())->validate();
        $id = $params['id'];
        unset($params['id']);
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');

        /** @var class-string<CrudRepository> $repository */
        $repository = $this->resource::$repository;
        $item = $repository::show($id, $params);

        if ($this->resource::shouldAuthorize('show')) {
            Gate::forUser($user)->authorize($this->resource::abilityFor('show'), $item);
        }

        return $item;
    }

    private function handleStore(McpRequest $request, Authenticatable $user): Model
    {
        $data = $this->validatedPayload($request, $this->resource::$storeRequestClass);
        $this->resource::prepareStoreData($data, $user);

        if ($this->resource::shouldAuthorize('store')) {
            Gate::forUser($user)->authorize(
                $this->resource::abilityFor('store'),
                [$this->resource::$model, $data],
            );
        }

        /** @var class-string<CrudRepository> $repository */
        $repository = $this->resource::$repository;

        return $repository::store($data);
    }

    private function handleUpdate(McpRequest $request, Authenticatable $user): Model
    {
        $id = Validator::make($request->all(), ['id' => 'required'])->validate()['id'];

        /** @var class-string<CrudRepository> $repository */
        $repository = $this->resource::$repository;
        $item = $repository::show($id);

        $data = $this->validatedPayload($request, $this->resource::$updateRequestClass, exclude: ['id']);
        $this->resource::prepareUpdateData($item, $data, $user);

        if ($this->resource::shouldAuthorize('update')) {
            Gate::forUser($user)->authorize(
                $this->resource::abilityFor('update'),
                [$item, $data],
            );
        }

        return $repository::update($item, $data);
    }

    private function handleDestroy(McpRequest $request, Authenticatable $user): Model
    {
        $id = Validator::make($request->all(), ['id' => 'required'])->validate()['id'];

        /** @var class-string<CrudRepository> $repository */
        $repository = $this->resource::$repository;
        $item = $repository::show($id);

        if ($this->resource::shouldAuthorize('destroy')) {
            Gate::forUser($user)->authorize($this->resource::abilityFor('destroy'), $item);
        }

        return $repository::destroy($item);
    }

    /**
     * @param  array<string, string|array<int, string>>|class-string<FormRequest>|class-string<Request>  $rulesOrRequest
     * @param  list<string>  $exclude
     * @return array<string, mixed>
     */
    private function validatedPayload(McpRequest $request, array|string $rulesOrRequest, array $exclude = []): array
    {
        $payload = $request->all();

        foreach ($exclude as $key) {
            unset($payload[$key]);
        }

        if (is_array($rulesOrRequest)) {
            return Validator::make($payload, $rulesOrRequest)->validate();
        }

        if (is_subclass_of($rulesOrRequest, FormRequest::class)) {
            return $this->validateWithFormRequest($rulesOrRequest, $payload);
        }

        return $payload;
    }

    /**
     * @param  class-string<FormRequest>  $formRequestClass
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateWithFormRequest(string $formRequestClass, array $data): array
    {
        /** @var FormRequest $formRequest */
        $formRequest = app($formRequestClass);
        $formRequest->merge($data);
        $formRequest->validateResolved();

        return $formRequest->validated();
    }

    /**
     * @param  array<string, string|array<int, string>>|class-string<FormRequest>|class-string<Request>  $rulesOrRequest
     * @return array<string, Type>
     */
    private function schemaFromRules(JsonSchema $schema, array|string $rulesOrRequest): array
    {
        if (! is_array($rulesOrRequest)) {
            return [];
        }

        $fields = [];

        foreach ($rulesOrRequest as $name => $rules) {
            $rule_string = is_array($rules) ? implode('|', $rules) : $rules;
            $required = str_contains($rule_string, 'required');

            $field = $schema->string()->description('Field: '.$name);

            $fields[$name] = $required ? $field->required() : $field->nullable();
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function toStructuredArray(mixed $result): array
    {
        if ($result instanceof Model) {
            return $result->toArray();
        }

        if ($result instanceof LengthAwarePaginator) {
            return $result->toArray();
        }

        if ($result instanceof Collection) {
            return ['data' => $result->toArray()];
        }

        if (is_array($result)) {
            return $result;
        }

        return ['data' => $result];
    }
}
