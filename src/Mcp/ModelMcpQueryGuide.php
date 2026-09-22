<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Mcp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds LLM-oriented documentation for CrudRepository query dialect + model-specific with/append/filter/scope catalogs.
 */
final class ModelMcpQueryGuide
{
    public static function whereDialectHelp(): string
    {
        return <<<'TXT'
WHERE / filter dialect (any extra index param that is not reserved becomes a filter):
- attribute=null → whereNull(attribute)
- attribute=notnull → whereNotNull(attribute)
- attribute=a,b,c → whereIn(attribute, [a,b,c])
- attribute=<number|bool|true|false> → where(attribute, =, value)
- attribute=<date> on date/datetime casts → whereDate(attribute, value)
- attribute=<date>..<date> (YYYY-MM-DD..YYYY-MM-DD) → whereDate between inclusive (also works for created_at/updated_at)
- attribute=<string> otherwise → where(attribute, LIKE, %value%)
- relation.attribute=value or relation-attribute=value → whereHas(relation, …) using the same value rules
- When using with=relation, you may also pass relation.attribute=value as an eager-load constraint (filters the relation and parent existence).
Reserved params (not column filters): search, with, without, append, order_by, order_by_direction, page, per_page, limit, scopes, select.
TXT;
    }

    public static function withSyntaxHelp(): string
    {
        return <<<'TXT'
with syntax: comma-separated relations. Nest with ".." (e.g. company,members..user). Max useful depth is usually 2–3.
order_by: field:asc|desc, or relation..field:asc. Multiple orders comma-separated.
select: columns; nested via relation..col1|col2.
TXT;
    }

    public static function appendSyntaxHelp(): string
    {
        return <<<'TXT'
append: comma-separated accessors. Nested appends use relation.accessor and require that relation to be loaded via with.
TXT;
    }

    public static function scopesSyntaxHelp(): string
    {
        return <<<'TXT'
scopes: CSV of local Eloquent scopes. Optional args after colon, e.g. active,search:term,forUser:12 → $query->active(); $query->search('term'); $query->forUser(12).
TXT;
    }

    /**
     * Full CrudRepository query dialect (shared across CRUD MCP tools).
     */
    public static function dialectGuide(): string
    {
        return implode("\n\n", [
            'CrudRepository MCP query dialect (applies to all CRUD index/show tools):',
            self::whereDialectHelp(),
            self::withSyntaxHelp(),
            self::appendSyntaxHelp(),
            self::scopesSyntaxHelp(),
            <<<'TXT'
Navigating relations:
- Prefer shallow with on index (e.g. with=company). Deepen with ".." only when needed (e.g. with=company_product_phases..company_product_phase_boards).
- Each CRUD tool lists the relations it allows. Do not invent relation names.
- Relation filters: relation.attribute=value (or relation-attribute=value) uses whereHas; with with=relation it also constrains the eager load.
- Heavy nests (boards, tasks, members) can be large — request them intentionally, often on a single record via show or limit=1.
TXT,
        ]);
    }

    /**
     * Model-specific catalog for a CRUD MCP tool description (no dialect boilerplate).
     *
     * @param  class-string<Model>  $model_class
     * @param  list<string>|null  $mcp_with
     * @param  list<string>|null  $mcp_scopes
     * @param  list<string>|null  $mcp_filterable
     */
    public static function forModel(
        string $model_class,
        int $relation_depth = 2,
        ?array $mcp_with = null,
        ?array $mcp_scopes = null,
        ?array $mcp_filterable = null,
    ): string {
        $relations = self::relationTree($model_class, $relation_depth, $mcp_with);
        $appends = self::appends($model_class);
        $filterable = self::filterableAttributes($model_class, $mcp_filterable);
        $scopes = self::localScopes($model_class, $mcp_scopes);
        $searchable = self::searchableFields($model_class);

        $lines = [
            'Catalog for '.class_basename($model_class).' (query syntax: call '.CrudQueryGuideTool::TOOL_NAME.'):',
            'Available relations (with)'
                .($mcp_with === null ? ', depth '.$relation_depth : '').': '
                .self::formatRelationTree($relations),
            'Available appends: '.($appends === [] ? '(none)' : implode(', ', $appends)),
            'Filterable attributes: '.($filterable === [] ? '(none)' : implode(', ', $filterable)),
            'Local scopes: '.($scopes === [] ? '(none)' : implode(', ', $scopes)),
            'searchable ($searchable): '.($searchable === [] ? '(search ignored)' : implode(', ', $searchable)),
        ];

        return implode("\n", $lines);
    }

    /**
     * Shorter description for the `with` schema field.
     *
     * @param  class-string<Model>  $model_class
     * @param  list<string>|null  $mcp_with
     */
    public static function withFieldDescription(string $model_class, int $relation_depth = 2, ?array $mcp_with = null): string
    {
        $relations = self::relationTree($model_class, $relation_depth, $mcp_with);

        return 'Eager-load relations (CSV; nest with ".."). Allowed: '.self::formatRelationTree($relations)
            .'. Syntax: '.CrudQueryGuideTool::TOOL_NAME.'.';
    }

    /**
     * @param  class-string<Model>  $model_class
     */
    public static function appendFieldDescription(string $model_class): string
    {
        $appends = self::appends($model_class);

        return 'Model appends (CSV). Available: '.($appends === [] ? '(none)' : implode(', ', $appends))
            .'. Syntax: '.CrudQueryGuideTool::TOOL_NAME.'.';
    }

    /**
     * @param  class-string<Model>  $model_class
     * @param  list<string>|null  $mcp_filterable
     * @return list<string>
     */
    public static function filterableAttributes(string $model_class, ?array $mcp_filterable = null): array
    {
        if ($mcp_filterable !== null) {
            $attrs = array_values(array_unique(array_filter(
                $mcp_filterable,
                fn (string $attr): bool => $attr !== '',
            )));
            sort($attrs);

            return $attrs;
        }

        try {
            /** @var Model $model */
            $model = new $model_class;
        } catch (Throwable) {
            return [];
        }

        /** @var list<string> $attrs */
        $attrs = array_values(array_unique(array_filter([
            ...$model->getFillable(),
            ...array_keys($model->getCasts()),
            $model->getKeyName(),
        ], fn (string $attr): bool => $attr !== '' && ! str_contains($attr, '.'))));

        sort($attrs);

        return $attrs;
    }

    /**
     * @param  class-string<Model>  $model_class
     * @return list<string>
     */
    public static function appends(string $model_class): array
    {
        try {
            /** @var Model $model */
            $model = new $model_class;
        } catch (Throwable) {
            return [];
        }

        $appends = $model->getAppends();
        sort($appends);

        return array_values($appends);
    }

    /**
     * @param  class-string<Model>  $model_class
     * @return list<string>
     */
    public static function searchableFields(string $model_class): array
    {
        try {
            /** @var Model $model */
            $model = new $model_class;
        } catch (Throwable) {
            return [];
        }

        $vars = get_object_vars($model);
        /** @var list<string>|null $searchable */
        $searchable = $vars['searchable'] ?? null;

        return is_array($searchable) ? array_values($searchable) : [];
    }

    /**
     * @param  class-string<Model>  $model_class
     * @param  list<string>|null  $mcp_scopes
     * @return list<string>
     */
    public static function localScopes(string $model_class, ?array $mcp_scopes = null): array
    {
        if ($mcp_scopes !== null) {
            $scopes = array_values(array_unique(array_filter(
                $mcp_scopes,
                fn (string $scope): bool => $scope !== '',
            )));
            sort($scopes);

            return $scopes;
        }

        try {
            $reflection = new ReflectionClass($model_class);
        } catch (Throwable) {
            return [];
        }

        /** @var list<string> $scopes */
        $scopes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if (! str_starts_with($name, 'scope') || $name === 'scope') {
                continue;
            }

            $scope = lcfirst(substr($name, 5));

            if ($scope !== '') {
                $scopes[] = $scope;
            }
        }

        sort($scopes);

        return array_values(array_unique($scopes));
    }

    /**
     * @param  class-string<Model>  $model_class
     * @param  list<string>|null  $mcp_with  Relation roots or nested paths with ".." (e.g. phases..boards)
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    public static function relationTree(string $model_class, int $depth = 2, ?array $mcp_with = null): array
    {
        if ($mcp_with !== null) {
            return self::treeFromAllowlist($mcp_with);
        }

        if ($depth < 1) {
            return [];
        }

        return self::discoverRelations($model_class, $depth, []);
    }

    /**
     * Build a relation tree exclusively from allowlist paths (supports ".." nesting).
     *
     * @param  list<string>  $mcp_with
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    private static function treeFromAllowlist(array $mcp_with): array
    {
        /** @var array<string, array<string, mixed>|list<mixed>> $tree */
        $tree = [];

        foreach ($mcp_with as $path) {
            $trimmed = str($path)->trim()->toString();

            if ($trimmed === '') {
                continue;
            }

            $parts = array_values(array_filter(
                explode('..', $trimmed),
                fn (string $part): bool => $part !== '',
            ));

            if ($parts === []) {
                continue;
            }

            $node = &$tree;

            foreach ($parts as $part) {
                if (! isset($node[$part]) || ! is_array($node[$part])) {
                    $node[$part] = [];
                }

                $node = &$node[$part];
            }

            unset($node);
        }

        self::ksortRecursive($tree);

        return $tree;
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $tree
     */
    private static function ksortRecursive(array &$tree): void
    {
        ksort($tree);

        foreach ($tree as &$children) {
            if (is_array($children) && $children !== [] && array_is_list($children) === false) {
                /** @var array<string, array<string, mixed>|list<mixed>> $children */
                self::ksortRecursive($children);
            }
        }
    }

    /**
     * @param  class-string<Model>  $model_class
     * @param  list<class-string<Model>>  $visited
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    private static function discoverRelations(string $model_class, int $depth, array $visited): array
    {
        if ($depth < 1 || in_array($model_class, $visited, true)) {
            return [];
        }

        $visited[] = $model_class;

        try {
            /** @var Model $model */
            $model = new $model_class;
            $reflection = new ReflectionClass($model_class);
        } catch (Throwable) {
            return [];
        }

        /** @var array<string, array<string, mixed>|list<mixed>> $tree */
        $tree = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $declaring = $method->getDeclaringClass()->getName();

            if (str_starts_with($declaring, 'Illuminate\\')) {
                continue;
            }

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if (! self::methodLooksLikeRelation($method)) {
                continue;
            }

            try {
                $relation = $method->invoke($model);
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $name = $method->getName();
            $related_class = $relation->getRelated()::class;

            $tree[$name] = $depth > 1
                ? self::discoverRelations($related_class, $depth - 1, $visited)
                : [];
        }

        ksort($tree);

        return $tree;
    }

    private static function methodLooksLikeRelation(ReflectionMethod $method): bool
    {
        $return = $method->getReturnType();

        if ($return instanceof ReflectionNamedType && ! $return->isBuiltin()) {
            $type = $return->getName();

            if ($type === Relation::class || is_subclass_of($type, Relation::class)) {
                return true;
            }
        }

        // Heuristic: many apps omit return types on relations.
        $name = $method->getName();

        return ! str_starts_with($name, 'get')
            && ! str_starts_with($name, 'set')
            && ! str_starts_with($name, 'scope')
            && ! str_starts_with($name, 'boot')
            && $name !== '__construct';
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $tree
     */
    private static function formatRelationTree(array $tree, string $prefix = ''): string
    {
        if ($tree === []) {
            return $prefix === '' ? '(none)' : '';
        }

        /** @var list<string> $parts */
        $parts = [];

        foreach ($tree as $name => $children) {
            $path = $prefix === '' ? $name : $prefix.'..'.$name;
            $parts[] = $path;

            if (is_array($children) && $children !== [] && array_is_list($children) === false) {
                /** @var array<string, array<string, mixed>|list<mixed>> $children */
                $nested = self::formatRelationTree($children, $path);

                if ($nested !== '') {
                    $parts[] = $nested;
                }
            }
        }

        return implode(', ', $parts);
    }
}
