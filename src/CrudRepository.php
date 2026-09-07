<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use BackedEnum;
use Bisual\LaravelShortcuts\Traits\HasUuid;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Stringable;

abstract class CrudRepository
{
    public static $model = Model::class;

    /**
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     */
    public static function index(array $params = [], bool $paginate = false, ?callable $functionExtraParametersTreatment = null)
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
            // handling with, order_by and select
            $clause = self::getClause($params);

            $searchable_fields = (new static::$model)->searchable;
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
            if (is_callable($functionExtraParametersTreatment)) {
                $functionExtraParametersTreatment($clause, $params);
            }

            $whereClause = [];

            if (count($params) > 0) {
                $model_inst = (new static::$model);

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
            if ($scopes !== null) {
                $scopes = explode(',', $scopes);
                foreach ($scopes as $scope) {
                    $scope_destruct = explode(':', $scope);
                    if (count($scope_destruct) > 0) {
                        $scope_method = array_shift($scope_destruct);
                        $scope_params = $scope_destruct;
                        $clause->{$scope_method}(...$scope_params);
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
        } elseif (is_callable($functionExtraParametersTreatment)) {
            $clause = (static::$model)::query();
            $functionExtraParametersTreatment($clause, $params);

            return $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();
        }

        return $paginate ? (static::$model)::paginate($perPage, ['*'], 'page', $page) : (static::$model)::get();
    }

    /**
     * @param  int|string|array<string, int|string>|object  $id
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     */
    public static function show(int|string|array|object $id, array $params = [], ?callable $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false)
    {
        // handling with, order_by and select
        $clause = self::getClause($params, $withoutGlobalScopes);

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($clause, $params);
        }

        if ($id instanceof static::$model) {
            return $id;
        } // ja li hem passat el model
        elseif (is_object($id)) {
            $id = $id->id;
        } // per si li hem passat algun altre objecte
        elseif (is_array($id)) {
            $id = $id['id'];
        } // per si li hem passat en array

        if (! is_numeric($id) && in_array(HasUuid::class, class_uses_recursive(static::$model))) {
            $clause->byUUID($id);
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

    public static function store(array $data)
    {
        return (static::$model)::create($data);
    }

    /**
     * @param  int|string|array<string, int|string>|object  $model
     */
    public static function update(int|string|array|object $model, array $params)
    {
        $model = self::show($model);

        $model->update($params);

        return $model->fresh();
    }

    /**
     * @param  int|string|array<string, int|string>|object  $model
     */
    public static function destroy(int|string|array|object $model, ?callable $functionExtraParametersTreatment = null)
    {
        $model = self::show($model);

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($model->id);
        }

        $model->delete();

        return $model;
    }

    /**
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     */
    protected static function getClause(array &$params = [], bool $withoutGlobalScopes = false): Builder
    {
        $clause = $withoutGlobalScopes ? (static::$model)::withoutGlobalScopes() : (static::$model)::query();

        // With
        $with = null;
        if (isset($params['with'])) {
            $with = $params['with'];
            unset($params['with']);
        }

        // Order by
        $order_by = null;
        if (isset($params['order_by'])) {
            $order_by = $params['order_by'];
            unset($params['order_by']);
        }

        // Select
        $select = null;
        if (isset($params['select'])) {
            $select = $params['select'];
            unset($params['select']);
        }

        if ($with || $order_by || $select) {
            $with_constraints = $with ? self::extractWithConstraints($params, $with) : [];

            self::handleWithOrderByAndSelect($clause, $with, $order_by, $select, $with_constraints);
        }

        return $clause;
    }

    /**
     * @param  array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>  $with_constraints
     */
    private static function handleWithOrderByAndSelect(Builder &$clause, ?string $with = null, ?string $order_by = null, ?string $select = null, array $with_constraints = []): void
    {
        $struct = self::getParamsStructure($with, $order_by, $select, $with_constraints);
        self::processParamsStructure($clause, $struct);
        self::applyRelationExistenceFilters($clause, $struct);
    }

    /**
     * @param  array{
     *     with?: array<string, array>,
     *     select?: list<string>,
     *     order_by?: array<string, string>,
     *     constraints?: list<array{attribute: string, value: int|string|bool|BackedEnum|null}>
     * }  $struct
     */
    private static function processParamsStructure(Builder|Relation &$clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void
    {
        // SELECT
        if (! empty($struct['select'])) {
            $clause->select(self::buildSelectRequiredFields($struct['select'], $parent_model, $relation));
        }

        // ORDER BY
        if (! empty($struct['order_by'])) {
            $order_field = array_key_first($struct['order_by']);
            $direction = $struct['order_by'][$order_field];
            $clause->orderBy($order_field, $direction);
        }

        // CONSTRAINTS on eager-loaded relations (?with=relation&relation.attribute=value)
        if (! empty($struct['constraints'])) {
            foreach ($struct['constraints'] as $constraint) {
                self::applyEagerLoadConstraint($clause, $constraint['attribute'], $constraint['value']);
            }
        }

        // WITH
        if (! empty($struct['with'])) {
            foreach ($struct['with'] as $nested_relation => $config) {
                $parent_model_for_relation = $clause->getModel();
                $relation_instance = self::getRelation($parent_model_for_relation, $nested_relation);

                if ($relation_instance instanceof MorphTo) {
                    $clause->with($nested_relation, function (MorphTo $query) use ($nested_relation, $config, $clause): void {
                        $parent_model = $clause->getModel();
                        self::processMorphToWith($query, $config, $parent_model, $nested_relation);
                    });

                    continue;
                }

                $clause->with($nested_relation, function (Relation $r) use ($nested_relation, $config, $clause): void {
                    $parent_model = $clause->getModel();
                    self::processParamsStructure($r, $config, $parent_model, $nested_relation);
                });
            }
        }
    }

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

        $morph_with = [];
        foreach (array_keys($morph_to->getDictionary()) as $type) {
            $class = Model::getActualClassNameForMorph((string) $type);
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
     * @param  array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>  $with_constraints
     * @return array{
     *     with?: array<string, array>,
     *     select?: list<string>,
     *     order_by?: array<string, string>,
     *     constraints?: list<array{attribute: string, value: int|string|bool|BackedEnum|null}>
     * }
     */
    private static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null, array $with_constraints = []): array
    {
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
                    $current = &$struct['with'];
                    foreach (explode('..', $select_segment) as $relation_path) {
                        if (str_contains($relation_path, '.')) {
                            [$key, $select] = explode('.', $relation_path, 2);
                            if (! array_key_exists($key, $current)) {
                                throw new Exception("You can't select field that are not in the relation.");
                            }

                            $current[$key]['select'] = explode('|', $select);
                        } else {
                            if (! array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't select field that are not in the relation.");
                            }

                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        self::attachWithConstraints($struct, $with_constraints);

        return $struct;
    }

    /**
     * Pull `relation.attribute=value` params that target eager-loaded relations.
     *
     * @param  array<string, int|string|bool|BackedEnum|null>  $params
     * @return array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>
     */
    private static function extractWithConstraints(array &$params, string $with): array
    {
        $relation_paths = [];
        foreach (explode(',', $with) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $current_path = [];
            foreach (explode('..', $segment) as $relation) {
                $current_path[] = $relation;
                $relation_paths[implode('.', $current_path)] = true;
            }
        }

        $constraints = [];
        foreach ($params as $attr => $val) {
            if (! is_string($attr) || ! str_contains($attr, '.')) {
                continue;
            }

            $last_dot = strrpos($attr, '.');
            $relation_path = substr($attr, 0, $last_dot);
            $attribute = substr($attr, $last_dot + 1);

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
     * @param  array{
     *     with?: array<string, array>,
     *     select?: list<string>,
     *     order_by?: array<string, string>,
     *     constraints?: list<array{attribute: string, value: int|string|bool|BackedEnum|null}>
     * }  $struct
     * @param  array<string, list<array{attribute: string, value: int|string|bool|BackedEnum|null}>>  $with_constraints
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

        if ($attribute === '' || $relation === '' || self::getRelation($model, explode('.', $relation)[0]) === null) {
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
     * @param  array{
     *     with?: array<string, array>,
     *     select?: list<string>,
     *     order_by?: array<string, string>,
     *     constraints?: list<array{attribute: string, value: int|string|bool|BackedEnum|null}>
     * }  $struct
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

        $functionExtraParametersTreatments = [];

        foreach (array_keys($morph_to->getDictionary()) as $type) {
            $class = Model::getActualClassNameForMorph((string) $type);
            $applicable = array_values(array_filter(
                $constraints,
                fn (array $constraint): bool => self::modelHasColumn($class, $constraint['attribute'])
            ));

            if ($applicable === []) {
                continue;
            }

            $functionExtraParametersTreatments[$class] = function (Builder $query) use ($applicable): void {
                foreach ($applicable as $constraint) {
                    self::applyEagerLoadConstraint($query, $constraint['attribute'], $constraint['value']);
                }
            };
        }

        if ($functionExtraParametersTreatments !== []) {
            $morph_to->constrain($functionExtraParametersTreatments);
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
     * Get the $model->$relation foreign key data.
     *
     * @return list<string>
     */
    private static function getForeignKeyData(Model $model, string $relation): array
    {
        if (! method_exists($model, $relation)) {
            throw new Exception("Relation '{$relation}' not found in model ".$model::class);
        }

        $relation_instance = $model->{$relation}();

        if (! $relation_instance instanceof Relation) {
            throw new Exception("Relation '{$relation}' not found in model ".$model::class);
        }

        if ($relation_instance instanceof MorphTo
            || $relation_instance instanceof MorphOne
            || $relation_instance instanceof MorphMany
        ) {
            return [
                $relation_instance->getForeignKeyName(),
                $relation_instance->getMorphType(),
            ];
        }

        if (method_exists($relation_instance, 'getForeignKeyName')) {
            return [$relation_instance->getForeignKeyName()];
        }

        return [];
    }

    /**
     * Build the select required fomat and fields.
     *
     * @param  list<string>  $select_fields
     * @return list<string>
     */
    private static function buildSelectRequiredFields(array $select_fields, ?Model $parent_model = null, ?string $relation = null): array
    {
        return array_unique(array_merge( // array_unique if we get the id from the front
            ['id'],
            $select_fields,
            $parent_model && $relation ? self::getForeignKeyData($parent_model, $relation) : []
        ));
    }

    private static function appendAttribute(Model $record, Stringable $append): void
    {
        $is_appending_main_model = $append->doesntContain('.');

        if ($is_appending_main_model) {
            $attributes = $append;

            $record->append($attributes->toString());

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
