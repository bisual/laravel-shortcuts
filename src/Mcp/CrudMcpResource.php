<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Bisual\LaravelShortcuts\CrudController;
use Bisual\LaravelShortcuts\CrudRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;

/**
 * Declarative CRUD exposure over Laravel MCP, mirroring {@see CrudController}.
 *
 * @template TModel of Model
 * @template TRepository of CrudRepository<TModel>
 */
abstract class CrudMcpResource
{
    /** @var class-string<TRepository> */
    public static string $repository = CrudRepository::class;

    /** @var class-string<TModel> */
    public static string $model = Model::class;

    /**
     * When set, only these actions are registered as MCP tools.
     *
     * @var list<string>|null
     */
    public static ?array $only = null;

    /**
     * When set (and $only is null), these actions are excluded.
     *
     * @var list<string>|null
     */
    public static ?array $except = null;

    /**
     * Whether to authorize each action via the model Policy (same idea as CrudController::$authorize).
     *
     * @var array<string, bool>
     */
    public static array $authorize = [
        'index' => true,
        'show' => true,
        'store' => true,
        'update' => true,
        'destroy' => true,
    ];

    /**
     * Map CRUD action → Gate ability. Defaults match CrudController (viewAny/view/create/update/delete).
     * Override per action when the policy uses a custom name (e.g. index instead of viewAny).
     *
     * @var array<string, string>
     */
    public static array $abilities = [];

    /**
     * Extra index query validation rules (merged with {@see CrudRepository::indexValidationRules()}).
     *
     * @var array<string, string|array<int, string>>|class-string<FormRequest>
     */
    public static array|string $indexQueryValidations = [];

    /** @var array<string, string|array<int, string>>|class-string<FormRequest>|class-string<Request> */
    public static $storeRequestClass = Request::class;

    /** @var array<string, string|array<int, string>>|class-string<FormRequest>|class-string<Request> */
    public static $updateRequestClass = Request::class;

    /**
     * Optional human descriptions per action (tool description).
     *
     * @var array<string, string>
     */
    public static array $descriptions = [];

    /**
     * Override reserved parameter metadata (e.g. change `id` description for show).
     *
     * @var array<string, array{description?: string, required?: bool, type?: 'string'|'integer', validation?: string}>
     */
    public static array $parameterDefinitionOverrides = [];

    /**
     * Additional standalone MCP tools registered alongside the CRUD action tools.
     *
     * @var list<class-string<Tool>|Tool>
     */
    public static array $extraTools = [];

    /**
     * @return list<Tool|class-string<Tool>>
     */
    public static function tools(): array
    {
        if (! class_exists(Tool::class)) {
            throw new InvalidArgumentException(
                'laravel/mcp is required to use '.static::class.'. Run: composer require laravel/mcp'
            );
        }

        $tools = [];

        foreach (static::enabledActions() as $action) {
            $tools[] = new CrudMcpActionTool(static::class, $action);
        }

        foreach (static::$extraTools as $tool) {
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * @return list<string>
     */
    public static function enabledActions(): array
    {
        $all = [
            CrudRepository::ACTION_INDEX,
            CrudRepository::ACTION_SHOW,
            CrudRepository::ACTION_STORE,
            CrudRepository::ACTION_UPDATE,
            CrudRepository::ACTION_DESTROY,
        ];

        if (static::$only !== null) {
            return array_values(array_intersect($all, static::$only));
        }

        if (static::$except !== null) {
            return array_values(array_diff($all, static::$except));
        }

        return $all;
    }

    public static function abilityFor(string $action): string
    {
        $defaults = [
            'index' => 'viewAny',
            'show' => 'view',
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
        ];

        return static::$abilities[$action] ?? $defaults[$action] ?? $action;
    }

    public static function shouldAuthorize(string $action): bool
    {
        return static::$authorize[$action] ?? true;
    }

    public static function toolNameFor(string $action): string
    {
        return str(class_basename(static::$model))->kebab()->append('-'.$action)->toString();
    }

    public static function toolDescriptionFor(string $action): string
    {
        if (isset(static::$descriptions[$action])) {
            return static::$descriptions[$action];
        }

        $model = class_basename(static::$model);

        return match ($action) {
            'index' => "List {$model} records via CrudRepository::index. Supports reserved query params (with, order_by, page, scopes, search, …) plus column filters.",
            'show' => "Show a single {$model} by id via CrudRepository::show.",
            'store' => "Create a {$model} via CrudRepository::store.",
            'update' => "Update a {$model} by id via CrudRepository::update.",
            'destroy' => "Delete a {$model} by id via CrudRepository::destroy.",
            default => "{$model} {$action}",
        };
    }

    /**
     * Hook before index repository call (mutate $params by reference).
     *
     * @param  array<string, mixed>  $params
     */
    public static function prepareIndexParams(array &$params, Authenticatable $user): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function prepareStoreData(array &$data, Authenticatable $user): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function prepareUpdateData(Model $item, array &$data, Authenticatable $user): void
    {
        //
    }
}
