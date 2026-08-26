<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use BackedEnum;
use Bisual\LaravelShortcuts\Enums\FilterType;
use Bisual\LaravelShortcuts\Traits\HasUuid;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Stringable;

abstract class CrudRepository
{
    public static $model = Model::class;

    /*+
     * @params
     *      - with
     *      - without
     *      - append
     *      - ... other attributes to filter
     */
    public static function index(array $params = [], bool $paginate = false, $functionExtraParametersTreatment = null)
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

            $searchable_fields = (new static::$model())->searchable;
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
            if ($functionExtraParametersTreatment !== null) {
                $functionExtraParametersTreatment($clause, $params);
            }

            $whereClause = [];
            if (count($params) > 0) {
                $model_inst = (new static::$model);
                foreach ($params as $attr => $val) {
                    if ($val !== null && $val !== '') {
                        if (str_contains($attr, '-')) {
                            $separate = explode('-', $attr);
                            $relations = implode('-', array_slice($separate, 0, -1));
                            $attribute = $separate[count($separate) - 1];
                            $table = (new static::$model)->{$relations}()->getRelated()->getTable();
                            $clause->whereHas($relations, function ($q) use (&$attribute, &$val, &$table, &$model_inst): void {
                                if ($val === null || $val === 'null') {
                                    $q->whereNull($table.'.'.$attribute);
                                } elseif ($val === 'notnull') {
                                    $q->whereNotNull($table.'.'.$attribute);
                                } elseif (str_contains($val, ',')) {
                                    $q->whereIn($table.'.'.$attribute, explode(',', $val));
                                } elseif (is_numeric($val) || is_bool($val) || $val === 'false' || $val === 'true') {
                                    $q->where($table.'.'.$attribute, $val);
                                } else {
                                    $q->where($table.'.'.$attribute, 'like', "%{$val}%");
                                }
                            });
                        } elseif ($val === null || $val === 'null') {
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

            // with=relation..relation2,user..relation1

            // append=relation..relation2.append1
            // select=relation..relation2.select_field
            // Process Without
            if ($without) {
                foreach (explode(',', $without) as $w) {
                    $clause->without($w);
                }
            }

            // Process Searchable Fields
            if ($search) {
                $clause->where(function ($query) use (&$searchable_fields, &$search): void {
                    foreach ($searchable_fields as $idx => $search_field) {
                        $parts = explode('.', $search_field);
                        if (count($parts) === 2) {
                            if ($idx === 0) {
                                $query->whereHas($parts[0], function ($query) use (&$parts, &$search): void {
                                    $query->where($parts[1], 'like', "%{$search}%");
                                });
                            } else {
                                $query->orWhereHas($parts[0], function ($query) use (&$parts, &$search): void {
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
        if ($functionExtraParametersTreatment !== null) {
            $clause = (static::$model)::query();
            if ($functionExtraParametersTreatment !== null) {
                $functionExtraParametersTreatment($clause, $params);
            }

            return $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();
        }

        return $paginate ? (static::$model)::paginate($perPage, ['*'], 'page', $page) : (static::$model)::get();
    }

    public static function show($id, array $params = [], $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false)
    {
        // query params on deepest with
        $clause = self::getClause($params, $withoutGlobalScopes);

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($clause, $params);
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
            $clause->byUUID($id);
        } else {
            $clause->where(App::make(static::$model)->getKeyName(), $id);
        }

        $model = $clause->firstOrFail();

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

    public static function update($model, $params)
    {
        $model = self::show($model);

        $model->update($params);

        return $model->fresh();
    }

    public static function destroy($model, $functionExtraParametersTreatment = null)
    {
        $model = self::show($model);

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($model->id);
        }

        $model->delete();

        return $model;
    }

    /**
     * Create an array processing params.
     */
    private static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null, ?string $string_where = null): array
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
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current[$key]['select'] = explode('|', $select);
                        } else {
                            if (! array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        if ($string_where) {
            foreach (StringDelimitersHelper::explodeOutsideRanges(',', $string_where) as $where_segment) {
                // Default del bloque (sufijo ::parent|child|both al final del segmento)
                $default_filter_type = self::getFilterType($where_segment);

                $or_conditions = StringDelimitersHelper::explodeOutsideRanges('||', $where_segment);

                if (count($or_conditions) > 1) {
                    $condition_group = [
                        'or_group' => true,
                        'groups' => [],
                    ];

                    foreach ($or_conditions as $or_condition) {
                        $condition_group['groups'][] = [
                            'conditions' => self::parseAndConditions($or_condition, $default_filter_type),
                        ];
                    }

                    $struct['where_conditions'][] = $condition_group;
                } else {
                    foreach (self::parseAndConditions($where_segment, $default_filter_type) as $condition) {
                        $struct['where_conditions'][] = [
                            'filter_type' => $condition['filter_type'],
                            'condition' => $condition,
                        ];
                    }
                }
            }
        }

        return $struct;
    }

    /**
     * Split a segment by && (outside value delimiters) into condition arrays.
     * Each condition may override the default filter type with ::parent|child|both.
     *
     * @return array<int, array{key: string, operator: string, value: string, path: ?string, filter_type: FilterType}>
     */
    private static function parseAndConditions(string $segment, FilterType $default_filter_type = FilterType::Parent): array
    {
        $parts = StringDelimitersHelper::explodeOutsideRanges('&&', $segment);

        return array_map(function (string $condition) use ($default_filter_type): array {
            $filter_type = self::extractConditionFilterType($condition, $default_filter_type);
            $parsed = StructHelper::createConditionArray($condition);
            $parsed['filter_type'] = $filter_type;

            return $parsed;
        }, $parts);
    }

    /**
     * Extract optional ::filterType from a single condition, falling back to default.
     */
    private static function extractConditionFilterType(string &$condition, FilterType $default): FilterType
    {
        $parts = StringDelimitersHelper::explodeOutsideRanges('::', $condition);

        if (count($parts) === 1) {
            return $default;
        }

        $maybe_type = $parts[count($parts) - 1];
        $valid = array_column(FilterType::cases(), 'value');

        if (! in_array($maybe_type, $valid, true)) {
            return $default;
        }

        array_pop($parts);
        $condition = implode('::', $parts);

        return FilterType::from($maybe_type);
    }

    /**
     * Extract the filter type to apply on a where segment (group-level default).
     */
    private static function getFilterType(string &$where_segment): FilterType
    {
        $parts = StringDelimitersHelper::explodeOutsideRanges('::', $where_segment);

        if (count($parts) === 1) {
            return FilterType::Parent;
        }

        $maybe_type = $parts[count($parts) - 1];
        $valid = array_column(FilterType::cases(), 'value');

        if (! in_array($maybe_type, $valid, true)) {
            return FilterType::Parent;
        }

        array_pop($parts);
        $where_segment = implode('::', $parts);

        return FilterType::from($maybe_type);
    }

    /**
     * Transforms multiple values from string to array
     */
    private static function parseMultipleValues(string $raw_value, string $separator = '|'): array
    {
        return array_filter(
            array_map('trim', explode($separator, $raw_value)),
            fn ($v) => $v !== ''
        );
    }

    /**
     * Other private functions.
     */
    protected static function getClause(array &$params = [], bool $withoutGlobalScopes = false)
    {
        $clause = $withoutGlobalScopes ? (static::$model)::withoutGlobalScopes() : (static::$model)::query();

        $query_params = [];
        foreach (['with', 'order_by', 'select', 'where'] as $key) {
            if (isset($params[$key])) {
                $query_params[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        if ($query_params !== []) {
            self::buildQueryFromParams(
                $clause,
                $query_params['with'] ?? null,
                $query_params['order_by'] ?? null,
                $query_params['select'] ?? null,
                $query_params['where'] ?? null,
            );
        }

        return $clause;
    }

    private static function buildQueryFromParams(&$clause, ?string $with = null, ?string $order_by = null, ?string $select = null, $where = null): void
    {
        $struct = self::getParamsStructure($with, $order_by, $select, $where); // we generate the structure with the data that we receive
        self::processParamsStructure($clause, $struct);
        if ($where) {
            self::applyWhereConditionsToStructure($clause, $struct['where_conditions']);
        }
    }

    /**
     * Process the params structure.
     */
    private static function processParamsStructure(mixed &$clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void
    {
        // SELECT
        if (! empty($struct['select'])) {
            $clause->select(StructHelper::buildSelectRequiredFields($struct['select'], $parent_model, $relation));
        }

        // ORDER BY
        if (! empty($struct['order_by'])) {
            $order_field = array_key_first($struct['order_by']);
            $direction = $struct['order_by'][$order_field];
            $clause->orderBy($order_field, $direction);
        }

        // recursuvity stop condition
        if (empty($struct['with'])) {
            return;
        }

        foreach ($struct['with'] as $relation => $config) {
            $clause->with($relation, function ($query) use ($relation, $config, $clause): void {
                $parent_model = $clause->getModel(); // get the parent model
                self::processParamsStructure($query, $config, $parent_model, $relation);
            });

        }
    }

    private static function applyWhereConditionsToStructure(mixed &$clause, array $where_conditions): void
    {
        foreach ($where_conditions as $condition_group) {
            if (! empty($condition_group['or_group'])) {
                $clause->where(function ($q) use ($condition_group, &$clause): void {
                    foreach ($condition_group['groups'] as $and_group) {
                        $q->orWhere(function ($sub_q) use ($and_group, &$clause): void {
                            foreach ($and_group['conditions'] as $condition) {
                                $filter_type = $condition['filter_type'] ?? FilterType::Parent;
                                // whereHas/where van en el grupo; el with siempre sobre la query raíz
                                self::processSimpleCondition($sub_q, $condition, $filter_type, $clause);
                            }
                        });
                    }
                });
            } else {
                $condition = $condition_group['condition'];
                $filter_type = $condition['filter_type'] ?? $condition_group['filter_type'] ?? FilterType::Parent;
                self::processSimpleCondition($clause, $condition, $filter_type, $clause);
            }
        }
    }

    /**
     * Process simple condition ['key', 'operator', 'value', 'path'].
     *
     * @param  mixed  $eager_load_query  Query raíz donde fusionar constraints de eager load (Child/Both).
     */
    private static function processSimpleCondition(&$query, array $condition, FilterType $filter_type, mixed &$eager_load_query = null): void
    {
        $eager_load_query = $eager_load_query ?? $query;
        $has_path = ! empty($condition['path']);
        $relation_path = $has_path ? str_replace('..', '.', $condition['path']) : null;

        if (! $has_path) {
            self::processConditionOperator($query, $condition);

            return;
        }

        switch ($filter_type) {
            case FilterType::Parent:
                // Filtra el padre; no re-aplica with para no pisar select/order del processParamsStructure
                $query->whereHas($relation_path, function ($q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            case FilterType::Child:
                // Sólo filtra hijos cargados; fusiona con eager loads previos
                self::mergeEagerLoadConstraint($eager_load_query, $relation_path, function ($q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            case FilterType::Both:
                $query->whereHas($relation_path, function ($q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                self::mergeEagerLoadConstraint($eager_load_query, $relation_path, function ($q) use ($condition): void {
                    self::processConditionOperator($q, $condition);
                });
                break;

            default:
                throw new Exception("Unsupported filter type: {$filter_type->value}");
        }
    }

    /**
     * Merge a constraint into an existing eager load instead of overwriting it.
     */
    private static function mergeEagerLoadConstraint(mixed &$query, string $relation_path, \Closure $constraint): void
    {
        $eager_loads = $query->getEagerLoads();
        $segments = explode('.', $relation_path);
        $top = $segments[0];

        if (! isset($eager_loads[$top])) {
            $query->with([$relation_path => $constraint]);

            return;
        }

        $previous = $eager_loads[$top];

        if (count($segments) === 1) {
            $query->with([$top => function ($q) use ($previous, $constraint): void {
                if (is_callable($previous)) {
                    $previous($q);
                }
                $constraint($q);
            }]);

            return;
        }

        $nested_path = implode('.', array_slice($segments, 1));

        $query->with([$top => function ($q) use ($previous, $nested_path, $constraint): void {
            if (is_callable($previous)) {
                $previous($q);
            }
            self::mergeEagerLoadConstraint($q, $nested_path, $constraint);
        }]);
    }

    /**
     * Process diferent operators and build respective query
     */
    private static function processConditionOperator(&$query, array $condition)
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
