<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Bisual\LaravelShortcuts\CrudController;
use Bisual\LaravelShortcuts\CrudRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
     * Relation tree depth exposed in MCP `with` / tool descriptions (1 = direct relations only).
     * When `$mcp_with` is set, nesting is only documented under those roots.
     */
    public static int $mcp_relation_depth = 2;

    /**
     * Allowed eager-load relation roots for MCP index/show (`with`).
     * `null` = auto-discover all relations; `[]` = none allowed.
     *
     * @var list<string>|null
     */
    public static ?array $mcp_with = null;

    /**
     * Allowed local Eloquent scopes for MCP index (`scopes` CSV, name before `:`).
     * `null` = auto-discover all; `[]` = none from the client (hooks may still inject scopes after enforcement).
     *
     * @var list<string>|null
     */
    public static ?array $mcp_scopes = null;

    /**
     * Allowed column filter attributes for MCP index (extra params beyond reserved ones).
     * `null` = auto from fillable/casts/key; `[]` = no column filters.
     *
     * @var list<string>|null
     */
    public static ?array $mcp_filterable = null;

    /**
     * Optional rate limit for CRUD MCP tools (null/0 = disabled).
     */
    public static ?int $rateLimitMaxAttempts = null;

    public static int $rateLimitDecaySeconds = 60;

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
        return static::toolsFrom([static::class]);
    }

    /**
     * Register CRUD action tools for one or more resources, auto-including {@see CrudQueryGuideTool} once.
     *
     * @param  list<class-string<self>>  $resource_classes
     * @return list<Tool|class-string<Tool>>
     */
    public static function toolsFrom(array $resource_classes): array
    {
        if (! class_exists(Tool::class)) {
            throw new InvalidArgumentException(
                'laravel/mcp is required to use '.static::class.'. Run: composer require laravel/mcp'
            );
        }

        if ($resource_classes === []) {
            return [];
        }

        /** @var list<Tool|class-string<Tool>> $tools */
        $tools = [CrudQueryGuideTool::class];

        foreach ($resource_classes as $resource_class) {
            if (! is_subclass_of($resource_class, self::class)) {
                throw new InvalidArgumentException(
                    $resource_class.' must extend '.self::class
                );
            }

            foreach ($resource_class::enabledActions() as $action) {
                $tools[] = new CrudMcpActionTool($resource_class, $action);
            }

            foreach ($resource_class::$extraTools as $tool) {
                $tools[] = $tool;
            }
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
            $base = static::$descriptions[$action];
        } else {
            $model = class_basename(static::$model);

            $base = match ($action) {
                'index' => "List {$model} records via CrudRepository::index. Supports reserved query params (with, order_by, page, scopes, search, …) plus column filters.",
                'show' => "Show a single {$model} by id via CrudRepository::show.",
                'store' => "Create a {$model} via CrudRepository::store.",
                'update' => "Update a {$model} by id via CrudRepository::update.",
                'destroy' => "Delete a {$model} by id via CrudRepository::destroy.",
                default => "{$model} {$action}",
            };
        }

        if (in_array($action, ['index', 'show'], true)) {
            return $base."\n\n".ModelMcpQueryGuide::forModel(
                static::$model,
                static::$mcp_relation_depth,
                static::$mcp_with,
                static::$mcp_scopes,
                static::$mcp_filterable,
            );
        }

        return $base;
    }

    /**
     * Reject MCP query params outside the configured allowlists.
     * Call before {@see prepareIndexParams()} so hooks can still inject scopes (e.g. forUser).
     *
     * @param  array<string, mixed>  $params
     */
    public static function enforceMcpQueryAllowlists(array &$params): void
    {
        if (static::$mcp_with !== null && isset($params['with']) && is_string($params['with']) && $params['with'] !== '') {
            /** @var list<string> $allowed_roots */
            $allowed_roots = [];

            foreach (static::$mcp_with as $allowed_path) {
                $normalized = str($allowed_path)->trim()->replace('..', '.')->toString();

                if ($normalized === '') {
                    continue;
                }

                $allowed_roots[] = explode('.', $normalized)[0];
            }

            $allowed_roots = array_values(array_unique($allowed_roots));

            /** @var list<string> $invalid */
            $invalid = [];

            foreach (explode(',', $params['with']) as $part) {
                $path = str($part)->trim()->replace('..', '.')->toString();

                if ($path === '') {
                    continue;
                }

                $root = explode('.', $path)[0];

                if (! in_array($root, $allowed_roots, true)) {
                    $invalid[] = $path;
                }
            }

            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    'with' => 'Relation(s) not allowed for MCP: '.implode(', ', $invalid)
                        .'. Allowed: '.(static::$mcp_with === [] ? '(none)' : implode(', ', static::$mcp_with)),
                ]);
            }
        }

        if (static::$mcp_scopes !== null && isset($params['scopes']) && is_string($params['scopes']) && $params['scopes'] !== '') {
            /** @var list<string> $invalid */
            $invalid = [];

            foreach (explode(',', $params['scopes']) as $part) {
                $raw = str($part)->trim()->toString();

                if ($raw === '') {
                    continue;
                }

                $name = str($raw)->before(':')->toString();

                if (! in_array($name, static::$mcp_scopes, true)) {
                    $invalid[] = $name;
                }
            }

            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    'scopes' => 'Scope(s) not allowed for MCP: '.implode(', ', $invalid)
                        .'. Allowed: '.(static::$mcp_scopes === [] ? '(none)' : implode(', ', static::$mcp_scopes)),
                ]);
            }
        }

        if (static::$mcp_filterable === null) {
            return;
        }

        /** @var list<string> $allowed_keys */
        $allowed_keys = [
            ...array_keys(CrudRepository::parameterDefinitions()),
            ...static::$mcp_filterable,
        ];

        if (is_array(static::$indexQueryValidations)) {
            $allowed_keys = [...$allowed_keys, ...array_keys(static::$indexQueryValidations)];
        }

        /** @var list<string> $invalid */
        $invalid = [];

        foreach (array_keys($params) as $key) {
            if (! is_string($key) || in_array($key, $allowed_keys, true)) {
                continue;
            }

            $relation_root = null;

            if (str_contains($key, '.')) {
                $relation_root = str($key)->before('.')->toString();
            } elseif (str_contains($key, '-')) {
                $relation_root = str($key)->before('-')->toString();
            }

            if ($relation_root !== null && static::$mcp_with !== null && in_array($relation_root, static::$mcp_with, true)) {
                continue;
            }

            if ($relation_root !== null && static::$mcp_with === null) {
                continue;
            }

            $invalid[] = $key;
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'filters' => 'Filter(s) not allowed for MCP: '.implode(', ', $invalid)
                    .'. Allowed: '.(static::$mcp_filterable === [] ? '(none)' : implode(', ', static::$mcp_filterable)),
            ]);
        }
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
