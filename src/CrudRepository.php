<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use BackedEnum;
use Bisual\LaravelShortcuts\Enums\QueryRelationFilterTypeEnum;
use Bisual\LaravelShortcuts\Helpers\QueryParamsStringDelimitersHelper;
use Bisual\LaravelShortcuts\Helpers\QueryParamsStructureHelper;
use Bisual\LaravelShortcuts\Traits\HasUuid;
use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Stringable;

/**
 * @template TModel of Model
 *
 * @phpstan-type EagerConstraint array{attribute: string, value: int|string|bool|BackedEnum|null}
 * @phpstan-type WhereCondition array{key: string, operator: string, value: string, path: ?string, relation_filter_mode: QueryRelationFilterTypeEnum}
 * @phpstan-type WhereConditionGroup array{
 *     or_group?: bool,
 *     groups?: list<array{conditions: list<WhereCondition>}>,
 *     relation_filter_mode?: QueryRelationFilterTypeEnum,
 *     condition?: WhereCondition
 * }
 * @phpstan-type RelationLeaf array{
 *     with?: array<string, array{
 *         select?: list<string>,
 *         order_by?: array<string, string>,
 *         constraints?: list<EagerConstraint>
 *     }>,
 *     select?: list<string>,
 *     order_by?: array<string, string>,
 *     constraints?: list<EagerConstraint>
 * }
 * @phpstan-type RelationNode array{
 *     with?: array<string, RelationLeaf>,
 *     select?: list<string>,
 *     order_by?: array<string, string>,
 *     constraints?: list<EagerConstraint>
 * }
 * @phpstan-type QueryParamsStructure array{
 *     with?: array<string, RelationNode>,
 *     select?: list<string>,
 *     order_by?: array<string, string>,
 *     constraints?: list<EagerConstraint>,
 *     where_conditions?: list<WhereConditionGroup>
 * }
 */
abstract class CrudRepository
{
    /** @var class-string<TModel> */
    public static $model = Model::class;

    /**
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     * @return LengthAwarePaginator<int, TModel>|Collection<int, TModel>
     */
    public static function index(array $params = [], bool $paginate = false, ?callable $callback = null): LengthAwarePaginator|Collection
    {
        $perPage = $params['per_page'] ?? 15; // Obtener el número de elementos por página, predeterminado a 15
        unset($params['per_page']);
        $page = $params['page'] ?? 1; // Obtener el número de página, predeterminado a 1
        unset($params['page'], $params['total']);

        $limit = null;
        if (isset($params['limit'])) {
            $limit = $params['limit'];
            unset($params['limit']);
        }

        if (count($params) > 0) {
            // query params on deepest with
            $clause = self::getClause($params);

            $model_inst = new static::$model;
            $searchable = get_object_vars($model_inst)['searchable'] ?? null;
            /** @var list<string>|null $searchable_fields */
            $searchable_fields = is_array($searchable)
                ? array_values(array_filter($searchable, is_string(...)))
                : null;

            $search = null;
            if (isset($params['search']) && $searchable_fields !== null && count($searchable_fields) > 0) {
                $search = $params['search'];
                unset($params['search']);
            }

            // Scopes
            $scopes = null;
            if (array_key_exists('scopes', $params)) {
                $scopes = $params['scopes'];
                unset($params['scopes']);
            }

            // Without
            $without = null;
            if (array_key_exists('without', $params)) {
                $without = $params['without'];
                unset($params['without']);
            }

            // Append
            $append = null;
            if (array_key_exists('append', $params)) {
                $append = $params['append'];
                unset($params['append']);
            }

            // Extra parameters treatment
            if (is_callable($callback)) {
                $callback($clause, $params);
            }

            /** @var list<array<int, int|string|bool|null>> $whereClause */
            $whereClause = [];

            if (count($params) > 0) {
                foreach ($params as $attr => $val) {
                    if ($val !== null && $val !== '') {
                        $relation_filter = self::getRelationFilter($model_inst, $attr);

                        if ($relation_filter !== null) {
                            self::applyRelationExistenceFilter($clause, $model_inst, $relation_filter['relation'], $relation_filter['attribute'], $val);
                        } elseif ($val === 'null') {
                            $whereClause[] = [$attr, null]; // $q->whereNull($attribute);
                        } elseif ($val === 'notnull') {
                            $clause->whereNotNull($attr);
                        } elseif ($val instanceof BackedEnum) {
                            $clause->where($attr, $val);
                        } elseif (str_contains((string) $val, ',')) {
                            $clause->whereIn($attr, explode(',', $val));
                        } elseif (is_numeric($val) || is_bool($val) || $val === 'false' || $val === 'true') {
                            $whereClause[] = [$attr, $val];
                        } elseif ($model_inst->hasCast($attr, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])) {
                            $clause->whereDate($attr, Carbon::parse($val));
                        } else {
                            $whereClause[] = [$attr, 'like', "%{$val}%"];
                        }
                    }
                }
            }

            $clause = $clause->where($whereClause);

            // Process Scopes
            if (is_string($scopes)) {
                foreach (explode(',', $scopes) as $scope) {
                    $scope_destruct = explode(':', $scope);
                    $scope_method = array_shift($scope_destruct);
                    if ($scope_method !== '') {
                        $clause->{$scope_method}(...$scope_destruct);
                    }
                }
            }

            // Process Without
            if ($without) {
                foreach (explode(',', $without) as $w) {
                    $clause->without($w);
                }
            }

            // Process Searchable Fields
            if ($search) {
                $clause->where(function (Builder $query) use (&$searchable_fields, &$search): void {
                    foreach ($searchable_fields as $idx => $search_field) {
                        $parts = explode('.', $search_field);
                        if (count($parts) === 2) {
                            if ($idx === 0) {
                                $query->whereHas($parts[0], function (Builder $query) use (&$parts, &$search): void {
                                    $query->where($parts[1], 'like', "%{$search}%");
                                });
                            } else {
                                $query->orWhereHas($parts[0], function (Builder $query) use (&$parts, &$search): void {
                                    $query->where($parts[1], 'like', "%{$search}%");
                                });
                            }
                        } elseif ($idx === 0) {
                            $query->where($search_field, 'like', "%{$search}%");
                        } else {
                            $query->orWhere($search_field, 'like', "%{$search}%");
                        }
                    }
                });
            }

            if ($paginate) {
                $data = $clause->paginate($perPage, ['*'], 'page', $page);
            } else {
                if ($limit) {
                    $clause->limit($limit);
                }
                $data = $clause->get();
            }

            if ($append !== null) {
                foreach ($data as $record) {
                    foreach (str($append)->explode(',') as $append_item) {
                        self::appendAttribute($record, str($append_item)->trim());
                    }
                }
            }

            return $data;
        }
        if (is_callable($callback)) {
            $clause = (static::$model)::query();
            $callback($clause, $params);

            return $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();
        }

        return $paginate
            ? (static::$model)::query()->paginate($perPage, ['*'], 'page', $page)
            : (static::$model)::query()->get();
    }

    /**
     * @param  int|string|array<string, int|string>|object  $id
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     * @return TModel
     */
    public static function show(int|string|array|object $id, array $params = [], ?callable $callback = null, bool $withoutGlobalScopes = false): Model
    {
        // query params on deepest with
        $clause = self::getClause($params, $withoutGlobalScopes);

        if ($callback !== null) {
            $callback($clause, $params);
        }

        if ($id instanceof static::$model) {
            return $id;
        } // ja li hem passat el model
        if (is_object($id)) {
            $id = $id->id;
        } // per si li hem passat algun altre objecte
        elseif (is_array($id)) {
            $id = $id['id'];
        } // per si li hem passat en array

        if (! is_numeric($id) && in_array(HasUuid::class, class_uses_recursive(static::$model))) {
            $model = new static::$model;
            $uuid_field = method_exists($model, 'getUUIDFieldName')
                ? $model->getUUIDFieldName()
                : 'uuid';
            $clause->where($uuid_field, $id);
        } else {
            $clause->where(App::make(static::$model)->getKeyName(), $id);
        }

        $model = $clause->sole();

        if (isset($params['append']) && $params['append'] !== '') {
            foreach (explode(',', $params['append']) as $append) {
                $model->append($append);
            }
        }

        return $model;
    }

    /**
     * @param  array<string, array|bool|float|int|object|string|null>  $data
     * @return TModel
     */
    public static function store(array $data): Model
    {
        return (static::$model)::query()->create($data);
    }

    /**
     * @param  int|string|array<string, int|string>|object  $model
     * @param  array<string, array|bool|float|int|object|string|null>  $params
     * @return TModel
     */
    public static function update(int|string|array|object $model, array $params): Model
    {
        $model = self::show($model);

        $model->update($params);

        return $model->refresh();
    }

    /**
     * @param  int|string|array<string, int|string>|object  $model
     * @return TModel
     */
    public static function destroy(int|string|array|object $model, ?callable $callback = null): Model
    {
        $model = self::show($model);

        if ($callback !== null) {
            $callback($model->getKey());
        }

        $model->delete();

        return $model;
    }

    /**
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     * @return Builder<TModel>
     */
    protected static function getClause(array &$params = [], bool $withoutGlobalScopes = false): Builder
    {
        $clause = $withoutGlobalScopes
            ? (static::$model)::query()->withoutGlobalScopes()
            : (static::$model)::query();

        $query_params = [];
        foreach (['with', 'order_by', 'select', 'where'] as $key) {
            if (isset($params[$key])) {
                $query_params[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        if ($query_params !== []) {
            $with = isset($query_params['with']) && is_string($query_params['with']) ? $query_params['with'] : null;
            $order_by = isset($query_params['order_by']) && is_string($query_params['order_by']) ? $query_params['order_by'] : null;
            $select = isset($query_params['select']) && is_string($query_params['select']) ? $query_params['select'] : null;
            $where = isset($query_params['where']) && is_string($query_params['where']) ? $query_params['where'] : null;
            $with_constraints = $with !== null ? self::extractWithConstraints($params, $with) : [];

            self::buildQueryFromParams(
                $clause,
                $with,
                $order_by,
                $select,
                $where,
                $with_constraints,
            );
        }

        return $clause;
    }

    /**
     * @param  array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>  $with_constraints
     */
    private static function buildQueryFromParams(Builder $clause, ?string $with = null, ?string $order_by = null, ?string $select = null, ?string $where = null, array $with_constraints = []): void
    {
        $struct = self::getParamsStructure($with, $order_by, $select, $where, $with_constraints);
        self::processParamsStructure($clause, $struct);
        self::applyRelationExistenceFilters($clause, $struct);

        if (filled($where)) {
            self::applyWhereConditionsToStructure($clause, $struct['where_conditions'] ?? []);
        }
    }

    /**
     * Build the structure for gived query params.
     *
     * @param  QueryParamsStructure|RelationNode  $struct
     */
    private static function processParamsStructure(Builder|Relation $clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void
    {
        $builder = $clause instanceof Relation ? $clause->getQuery() : $clause;

        // SELECT
        if (! empty($struct['select'])) {
            $builder->select(QueryParamsStructureHelper::buildSelectRequiredFields($struct['select'], $parent_model, $relation));
        }

        // ORDER BY
        if (! empty($struct['order_by'])) {
            $order_field = array_key_first($struct['order_by']);
            $direction = $struct['order_by'][$order_field];
            $builder->orderBy($order_field, $direction);
        }

        // CONSTRAINTS on eager-loaded relations (?with=relation&relation.attribute=value)
        if (! empty($struct['constraints'])) {
            foreach ($struct['constraints'] as $constraint) {
                self::applyEagerLoadConstraint($builder, $constraint['attribute'], $constraint['value']);
            }
        }

        // WITH
        if (! empty($struct['with'])) {
            foreach ($struct['with'] as $nested_relation => $config) {
                $parent_model_for_relation = $builder->getModel();
                $relation_instance = self::getRelation($parent_model_for_relation, $nested_relation);

                if ($relation_instance instanceof MorphTo) {
                    $builder->with($nested_relation, function (MorphTo $query) use ($nested_relation, $config, $builder): void {
                        $parent_model = $builder->getModel();
                        self::processMorphToWith($query, $config, $parent_model, $nested_relation);
                    });

                    continue;
                }

                $builder->with($nested_relation, function (Relation $r) use ($nested_relation, $config, $builder): void {
                    $parent_model = $builder->getModel();
                    self::processParamsStructure($r, $config, $parent_model, $nested_relation);
                });
            }
        }
    }

    /**
     * @param  RelationNode  $config
     */
    private static function processMorphToWith(MorphTo $morph_to, array $config, Model $parent_model, string $relation): void
    {
        $nested_with = $config['with'] ?? [];
        $constraints = $config['constraints'] ?? [];
        unset($config['with'], $config['constraints']);

        self::processParamsStructure($morph_to, $config, $parent_model, $relation);
        self::constrainMorphTo($morph_to, $constraints);

        if ($nested_with === []) {
            return;
        }

        /** @var array<class-string<Model>, array<string, Closure(Relation): void>> $morph_with */
        $morph_with = [];

        foreach (array_keys($morph_to->getDictionary()) as $type) {
            $class = Model::getActualClassNameForMorph((string) $type);

            /** @var array<string, Closure(Relation): void> $with_for_type */
            $with_for_type = [];

            foreach ($nested_with as $nested_relation => $nested_config) {
                if (! method_exists($class, $nested_relation)) {
                    continue;
                }

                $with_for_type[$nested_relation] = function (Relation $r) use ($class, $nested_relation, $nested_config): void {
                    self::processParamsStructure($r, $nested_config, new $class, $nested_relation);
                };
            }

            if ($with_for_type !== []) {
                $morph_with[$class] = $with_for_type;
            }
        }

        if ($morph_with !== []) {
            $morph_to->morphWith($morph_with);
        }
    }

    /**
     * Create an array processing params.
     *
     * @param  array<string, list<EagerConstraint>>  $with_constraints
     * @return QueryParamsStructure
     */
    private static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null, ?string $string_where = null, array $with_constraints = []): array
    {
        /** @var QueryParamsStructure $struct */
        $struct = [];

        if ($string_with) {
            // process $string_with --> skeleton of $struct
            foreach (explode(',', $string_with) as $with_segment) {
                $current = &$struct;
                foreach (explode('..', $with_segment) as $relation) {
                    if (! isset($current['with'][$relation])) {
                        $current['with'][$relation] = ['with' => []];
                    }

                    $current = &$current['with'][$relation];
                }
            }
        }

        if ($string_order_by) {
            // process $string_order_by
            foreach (explode(',', $string_order_by) as $order_by_segment) {
                // if it doesn't have '..', we are on the main table
                if (! str_contains($order_by_segment, '.')) {
                    $current = &$struct;
                    $parts = explode(':', $order_by_segment);
                    $order_by_direction = (count($parts) === 2) ? array_pop($parts) : 'asc';
                    $current['order_by'] = [
                        $parts[0] => $order_by_direction,
                    ];
                } else {
                    $struct['with'] ??= [];
                    /** @var array<string, RelationNode> $current */
                    $current = &$struct['with'];
                    foreach (explode('..', $order_by_segment) as $relation_path) {
                        if (str_contains($relation_path, '.')) {
                            $parts = explode(':', $relation_path);
                            $order_by_direction = (count($parts) === 2) ? array_pop($parts) : 'asc';
                            [$key, $order_by] = explode('.', $parts[0], 2);
                            if (! array_key_exists($key, $current)) {
                                throw new Exception("You can't order by field that are not in the relation.");
                            }

                            $current[$key]['order_by'] = [
                                $order_by => $order_by_direction,
                            ];
                        } else {
                            if (! array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't order by field that are not in the relation.");
                            }

                            $current[$relation_path]['with'] ??= [];
                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        if ($string_select) {
            // process $string_select
            foreach (explode(',', $string_select) as $select_segment) {
                // if it doesn't have '..', we are on the main table
                if (! str_contains($select_segment, '.')) {
                    $current = &$struct;
                    $current['select'] = explode('|', $select_segment);
                } else {
                    $struct['with'] ??= [];
                    /** @var array<string, RelationNode> $current */
                    $current = &$struct['with'];
                    foreach (explode('..', $select_segment) as $relation_path) {
                        if (str_contains($relation_path, '.')) {
                            [$key, $select] = explode('.', $relation_path, 2);
                            if (! array_key_exists($key, $current)) {
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current[$key]['select'] = explode('|', $select);
                        } else {
                            if (! array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current[$relation_path]['with'] ??= [];
                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        if ($string_where) {
            // process $string_where
            foreach (QueryParamsStringDelimitersHelper::explodeOutsideRanges(',', $string_where) as $where_segment) {
                // Default del bloque (sufijo ::parent|child|both al final del segmento)
                $default_relation_filter_mode = self::getQueryRelationFilterType($where_segment);

                $or_conditions = QueryParamsStringDelimitersHelper::explodeOutsideRanges('||', $where_segment);

                if (count($or_conditions) > 1) {
                    $condition_group = [
                        'or_group' => true,
                        'groups' => [],
                    ];

                    foreach ($or_conditions as $or_condition) {
                        $condition_group['groups'][] = [
                            'conditions' => self::parseAndConditions($or_condition, $default_relation_filter_mode),
                        ];
                    }

                    $struct['where_conditions'][] = $condition_group;
                } else {
                    foreach (self::parseAndConditions($where_segment, $default_relation_filter_mode) as $condition) {
                        $struct['where_conditions'][] = [
                            'relation_filter_mode' => $condition['relation_filter_mode'],
                            'condition' => $condition,
                        ];
                    }
                }
            }
        }

        self::attachWithConstraints($struct, $with_constraints);

        return $struct;
    }

    /**
     * Extract group-level ::parent|child|both default from a where segment.
     */
    private static function getQueryRelationFilterType(string &$where_segment): QueryRelationFilterTypeEnum
    {
        $parts = QueryParamsStringDelimitersHelper::explodeOutsideRanges('::', $where_segment);

        if (count($parts) === 1) {
            return QueryRelationFilterTypeEnum::Parent;
        }

        $maybe_type = $parts[count($parts) - 1];
        $valid = array_column(QueryRelationFilterTypeEnum::cases(), 'value');

        if (! in_array($maybe_type, $valid, true)) {
            return QueryRelationFilterTypeEnum::Parent;
        }

        array_pop($parts);
        $where_segment = implode('::', $parts);

        return QueryRelationFilterTypeEnum::from($maybe_type);
    }

    /**
     * Pull `relation.attribute=value` params that target eager-loaded relations.
     *
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     * @return array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>
     */
    private static function extractWithConstraints(array &$params, string $with): array
    {
        /** @var array<string, true> $relation_paths */
        $relation_paths = [];

        foreach (explode(',', $with) as $segment) {
            $segment = mb_trim($segment);
            if ($segment === '') {
                continue;
            }

            /** @var list<string> $current_path */
            $current_path = [];

            foreach (explode('..', $segment) as $relation) {
                $current_path[] = $relation;
                $relation_paths[implode('.', $current_path)] = true;
            }
        }

        /** @var array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>> $constraints */
        $constraints = [];

        foreach ($params as $attr => $val) {
            if (! str_contains($attr, '.')) {
                continue;
            }

            $last_dot = mb_strrpos($attr, '.');
            $relation_path = mb_substr($attr, 0, $last_dot);
            $attribute = mb_substr($attr, $last_dot + 1);

            if ($attribute === '' || ! isset($relation_paths[$relation_path])) {
                continue;
            }

            $constraints[$relation_path][] = [
                'attribute' => $attribute,
                'value' => $val,
            ];
            unset($params[$attr]);
        }

        return $constraints;
    }

    /**
     * @param  QueryParamsStructure  $struct
     * @param  array<string, list<EagerConstraint>>  $with_constraints
     */
    private static function attachWithConstraints(array &$struct, array $with_constraints): void
    {
        foreach ($with_constraints as $path => $filters) {
            $current = &$struct;
            foreach (explode('.', $path) as $relation) {
                if (! isset($current['with'][$relation])) {
                    unset($current);

                    continue 2;
                }

                $current = &$current['with'][$relation];
            }

            $current['constraints'] = array_merge($current['constraints'] ?? [], $filters);
            unset($current);
        }
    }

    private static function applyEagerLoadConstraint(Builder|Relation $clause, string $attribute, int|string|bool|BackedEnum|null $val): void
    {
        if ($val === null || $val === 'null') {
            $clause->whereNull($attribute);
        } elseif ($val === 'notnull') {
            $clause->whereNotNull($attribute);
        } elseif ($val instanceof BackedEnum) {
            $clause->where($attribute, $val);
        } elseif (str_contains((string) $val, ',')) {
            $clause->whereIn($attribute, explode(',', $val));
        } elseif (is_numeric($val) || is_bool($val) || $val === 'false' || $val === 'true') {
            $clause->where($attribute, $val);
        } else {
            $clause->where($attribute, 'like', "%{$val}%");
        }
    }

    private static function getRelation(Model $model, string $relation): ?Relation
    {
        if (! method_exists($model, $relation)) {
            return null;
        }

        $relation_instance = $model->{$relation}();

        return $relation_instance instanceof Relation ? $relation_instance : null;
    }

    /**
     * @return array{relation: string, attribute: string}|null
     */
    private static function getRelationFilter(Model $model, string $attr): ?array
    {
        $separator = str_contains($attr, '.') ? '.' : '-';

        $parts = explode($separator, $attr);

        if (count($parts) < 2) {
            return null;
        }

        $attribute = array_pop($parts);
        $relation = implode($separator, $parts);

        $is_invalid_relation_filter = self::getRelation($model, explode('.', $relation)[0]) === null;

        if ($is_invalid_relation_filter) {
            return null;
        }

        return [
            'relation' => $relation,
            'attribute' => $attribute,
        ];
    }

    /**
     * Filter parent rows by related attributes. MorphTo uses whereHasMorph so each type is queried on its own table.
     */
    private static function applyRelationExistenceFilter(Builder $clause, Model $model, string $relation, string $attribute, int|string|bool|BackedEnum|null $val): void
    {
        $top_relation = explode('.', $relation)[0];
        $relation_instance = self::getRelation($model, $top_relation);

        if ($relation_instance instanceof MorphTo) {
            $types = self::morphTypesHavingColumn($model, $relation_instance, $attribute);

            $clause->whereHasMorph($top_relation, $types, function (Builder $query) use ($attribute, $val): void {
                self::applyEagerLoadConstraint($query, $attribute, $val);
            });

            return;
        }

        if ($relation_instance === null) {
            return;
        }

        $table = $relation_instance->getRelated()->getTable();

        $clause->whereHas($relation, function (Builder $q) use ($attribute, $val, $table): void {
            self::applyEagerLoadConstraint($q, $table.'.'.$attribute, $val);
        });
    }

    /**
     * @param  QueryParamsStructure  $struct
     */
    private static function applyRelationExistenceFilters(Builder $clause, array $struct): void
    {
        foreach ($struct['with'] ?? [] as $relation => $config) {
            if (empty($config['constraints'])) {
                continue;
            }

            $model = $clause->getModel();

            foreach ($config['constraints'] as $constraint) {
                self::applyRelationExistenceFilter($clause, $model, $relation, $constraint['attribute'], $constraint['value']);
            }
        }
    }

    /**
     * @param  list<array{attribute: string, value: int|string|bool|BackedEnum|null}>  $constraints
     */
    private static function constrainMorphTo(MorphTo $morph_to, array $constraints): void
    {
        if ($constraints === []) {
            return;
        }

        /** @var array<class-string<Model>, Closure(Builder): void> $callbacks */
        $callbacks = [];

        foreach (array_keys($morph_to->getDictionary()) as $type) {
            $class = Model::getActualClassNameForMorph((string) $type);

            $applicable = array_values(array_filter(
                $constraints,
                fn (array $constraint): bool => self::modelHasColumn($class, $constraint['attribute'])
            ));

            if ($applicable === []) {
                continue;
            }

            $callbacks[$class] = function (Builder $query) use ($applicable): void {
                foreach ($applicable as $constraint) {
                    self::applyEagerLoadConstraint($query, $constraint['attribute'], $constraint['value']);
                }
            };
        }

        if ($callbacks !== []) {
            $morph_to->constrain($callbacks);
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private static function morphTypesHavingColumn(Model $model, MorphTo $relation, string $attribute): array
    {
        /** @var Collection<int, string|BackedEnum> $morph_types */
        $morph_types = $model->newModelQuery()
            ->distinct()
            ->pluck($relation->getMorphType())
            ->filter();

        return $morph_types
            ->map(function (string|BackedEnum $type) use ($attribute): ?string {
                $type = $type instanceof BackedEnum ? (string) $type->value : $type;
                $class = Relation::getMorphedModel($type) ?? Model::getActualClassNameForMorph($type);

                return self::modelHasColumn($class, $attribute) ? $class : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function modelHasColumn(string $class, string $column): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        /** @var Model $model */
        $model = new $class;

        return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
    }

    /**
     * Split a segment by && into condition arrays.
     * Each condition may override the default filter type with ::parent|child|both.
     *
     * @return array<int, array{key: string, operator: string, value: string, path: ?string, relation_filter_mode: QueryRelationFilterTypeEnum}>
     */
    private static function parseAndConditions(string $segment, QueryRelationFilterTypeEnum $default_relation_filter_mode = QueryRelationFilterTypeEnum::Parent): array
    {
        $parts = QueryParamsStringDelimitersHelper::explodeOutsideRanges('&&', $segment);

        return array_map(function (string $condition) use ($default_relation_filter_mode): array {
            $relation_filter_mode = self::extractConditionQueryRelationFilterType($condition, $default_relation_filter_mode);
            $parsed = QueryParamsStructureHelper::createConditionArray($condition);
            $parsed['relation_filter_mode'] = $relation_filter_mode;

            return $parsed;
        }, $parts);
    }

    /**
     * Extract optional ::filterType from a single condition, falling back to default.
     */
    private static function extractConditionQueryRelationFilterType(string &$condition, QueryRelationFilterTypeEnum $default): QueryRelationFilterTypeEnum
    {
        $parts = QueryParamsStringDelimitersHelper::explodeOutsideRanges('::', $condition);

        if (count($parts) === 1) {
            return $default;
        }

        $maybe_type = $parts[count($parts) - 1];
        $valid = array_column(QueryRelationFilterTypeEnum::cases(), 'value');

        if (! in_array($maybe_type, $valid, true)) {
            return $default;
        }

        array_pop($parts);
        $condition = implode('::', $parts);

        return QueryRelationFilterTypeEnum::from($maybe_type);
    }

    /**
     * Transform multiple values from string to array (separator |).
     *
     * @return list<string>
     */
    private static function parseMultipleValues(string $raw_value, string $separator = '|'): array
    {
        return array_values(array_filter(
            array_map('trim', explode($separator, $raw_value)),
            fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param  list<WhereConditionGroup>  $where_conditions
     */
    private static function applyWhereConditionsToStructure(Builder $clause, array $where_conditions): void
    {
        foreach ($where_conditions as $condition_group) {
            if (! empty($condition_group['or_group'])) {
                $clause->where(function (Builder $query) use ($condition_group, $clause): void {
                    foreach ($condition_group['groups'] ?? [] as $and_group) {
                        $query->orWhere(function (Builder $sub_query) use ($and_group, $clause): void {
                            foreach ($and_group['conditions'] as $condition) {
                                $relation_filter_mode = $condition['relation_filter_mode'];
                                // whereHas/where van en el grupo; el with siempre sobre la query raíz
                                self::processSimpleCondition($sub_query, $condition, $relation_filter_mode, $clause);
                            }
                        });
                    }
                });
            } else {
                $condition = $condition_group['condition'] ?? null;
                if ($condition === null) {
                    continue;
                }

                $relation_filter_mode = $condition['relation_filter_mode'];
                self::processSimpleCondition($clause, $condition, $relation_filter_mode, $clause);
            }
        }
    }

    /**
     * Process simple condition ['key', 'operator', 'value', 'path'].
     *
     * @param  array{key: string, operator: string, value: string, path: ?string, relation_filter_mode: QueryRelationFilterTypeEnum}  $condition
     */
    private static function processSimpleCondition(Builder $query, array $condition, QueryRelationFilterTypeEnum $relation_filter_mode, ?Builder $eager_load_query = null): void
    {
        $eager_load_query = $eager_load_query ?? $query;
        $relation_path = $condition['path'] !== null
            ? str_replace('..', '.', $condition['path'])
            : null;

        if (blank($relation_path)) {
            self::processConditionOperator($query, $condition);

            return;
        }

        switch ($relation_filter_mode) {
            case QueryRelationFilterTypeEnum::Parent:
                // Filtra el padre; no re-aplica with para no pisar select/order del processParamsStructure
                $query->whereHas($relation_path, function (Builder $q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            case QueryRelationFilterTypeEnum::Child:
                // Sólo filtra hijos cargados; fusiona con eager loads previos
                self::mergeEagerLoadConstraint($eager_load_query, $relation_path, function (Builder $q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            case QueryRelationFilterTypeEnum::Both:
                $query->whereHas($relation_path, function (Builder $q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                self::mergeEagerLoadConstraint($eager_load_query, $relation_path, function (Builder $q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            default:
                throw new Exception("Unsupported relation filter mode: {$relation_filter_mode->value}");
        }
    }

    /**
     * Merge a constraint into an existing eager load instead of overwriting it.
     */
    private static function mergeEagerLoadConstraint(Builder|Relation $query, string $relation_path, Closure $constraint): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        $eager_loads = $builder->getEagerLoads();
        $segments = explode('.', $relation_path);
        $top = $segments[0];

        if (! isset($eager_loads[$top])) {
            $builder->with([$relation_path => $constraint]);

            return;
        }

        $previous = $eager_loads[$top];

        if (count($segments) === 1) {
            $builder->with([$top => function (Relation $relation) use ($previous, $constraint): void {
                if (is_callable($previous)) {
                    $previous($relation);
                }
                $constraint($relation->getQuery());
            }]);

            return;
        }

        $nested_path = implode('.', array_slice($segments, 1));

        $builder->with([$top => function (Relation $relation) use ($previous, $nested_path, $constraint): void {
            if (is_callable($previous)) {
                $previous($relation);
            }
            self::mergeEagerLoadConstraint($relation, $nested_path, $constraint);
        }]);
    }

    /**
     * Map operator + value to the corresponding Eloquent where*.
     *
     * @param  array{key: string, operator: string, value: string, path: ?string, relation_filter_mode: QueryRelationFilterTypeEnum}  $condition
     */
    private static function processConditionOperator(Builder $query, array $condition): void
    {
        $key = $condition['key'];
        $operator = $condition['operator'];
        $value = mb_trim($condition['value'], '<{}>');

        switch (true) {
            case $operator === '=':
            case $operator === '!=':
            case $operator === '>':
            case $operator === '<':
            case $operator === '>=':
            case $operator === '<=':
                $query->where($key, $operator, $value);
                break;

            case $operator === 'like':
                $query->where($key, 'like', $value);
                break;

            case $operator === 'notLike':
                $query->where($key, 'not like', $value);
                break;

            case $operator === 'in':
                $arr_values = self::parseMultipleValues($value);
                $query->whereIn($key, $arr_values);
                break;

            case $operator === 'notIn':
                $arr_values = self::parseMultipleValues($value);
                $query->whereNotIn($key, $arr_values);
                break;

            case $operator === 'null':
                $query->whereNull($key);
                break;

            case $operator === 'notNull':
                $query->whereNotNull($key);
                break;

            case $operator === 'between':
                $arr_values = self::parseMultipleValues($value);
                if (count($arr_values) !== 2) {
                    throw new Exception("Operator {$condition['operator']} requires exactly two values.");
                }

                $query->whereBetween($key, $arr_values);
                break;

            case $operator === 'notBetween':
                $arr_values = self::parseMultipleValues($value);
                if (count($arr_values) !== 2) {
                    throw new Exception("Operator {$condition['operator']} requires exactly two values.");
                }

                $query->whereNotBetween($key, $arr_values);
                break;

            case str_starts_with($operator, 'date,'): // solo comparará fechas sin horas
                $real_operator = mb_substr($operator, 5);
                $query->whereDate($key, $real_operator, $value);
                break;

            default:
                throw new Exception("Unsupported operator: {$operator}");
        }
    }

    // -------------------------------------------------------------------------
    // F. Result post-processing
    // -------------------------------------------------------------------------

    private static function appendAttribute(Model $record, Stringable $append): void
    {
        $is_appending_main_model = $append->doesntContain('.');

        if ($is_appending_main_model) {
            $record->append($append->toString());

            return;
        }

        $relationship_names = $append->explode('.');

        $relationship_attributes = $relationship_names->pop();

        $current_record = $record;

        foreach ($relationship_names as $relationship_name) {
            if (! $current_record->relationLoaded($relationship_name)) {
                throw new Exception("Relation '{$relationship_name}' not loaded in model ".$record::class." when appending attribute '{$append}'. Load it using the 'with' parameter.");
            }

            /** @var Model|null|Collection<int, Model> $current_record */
            $current_record = $current_record->getRelation($relationship_name);
        }

        if ($current_record instanceof Model) {
            $current_record->append($relationship_attributes);

            return;
        }

        if ($current_record instanceof Collection) {
            $current_record->each(function (Model $related_model) use ($relationship_attributes): void {
                $related_model->append($relationship_attributes);
            });
        }
    }
}
