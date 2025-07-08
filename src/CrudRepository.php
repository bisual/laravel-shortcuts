<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Enums\FilterType;
use Bisual\LaravelShortcuts\Traits\HasUuid;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\App;

abstract class CrudRepository {
    public static $model = Model::class;

    /*+
     * @params
     *      - with
     *      - without
     *      - ... other attributes to filter
     */
    public static function index(array $params = [], bool $paginate = false, $functionExtraParametersTreatment = null) {
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
                        if ($idx === 0) {
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
                foreach ($data as $model) {
                    foreach (explode(',', $append) as $append_item) {
                        $model->append($append_item);
                    }
                }
            }

            return $data;
        } elseif ($functionExtraParametersTreatment !== null) {
            $clause = (static::$model)::query();
            if ($functionExtraParametersTreatment !== null) {
                $functionExtraParametersTreatment($clause, $params);
            }
            return $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();
        }

        return $paginate ? (static::$model)::paginate($perPage, ['*'], 'page', $page) : (static::$model)::get();
    }

    public static function show($id, array $params = [], $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false) {
        // query params on deepest with
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

        if (!is_numeric($id) && in_array(HasUuid::class, class_uses_recursive(static::$model))) {
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

    public static function store(array $data) {
        return (static::$model)::create($data);
    }

    public static function update($model, $params) {
        $model = self::show($model);

        $model->update($params);

        return $model->fresh();
    }

    public static function destroy($model, $functionExtraParametersTreatment = null) {
        $model = self::show($model);

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($model->id);
        }

        $model->delete();

        return $model;
    }

     /**
     * Other private functions.
     */
    protected static function getClause(array &$params = [], bool $withoutGlobalScopes = false) {
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

        // Where
        $where = null;
        if (isset($params['where'])) {
            $where = $params['where'];
            unset($params['where']);
        }

        if ($with || $order_by || $select || $where) { // TODO: cambiar esto a array
            self::buildQueryFromParams($clause, $with, $order_by, $select, $where);
        }

        return $clause;
    }

    private static function buildQueryFromParams(&$clause, ?string $with = null, ?string $order_by = null, ?string $select = null, $where = null): void {
        $struct = self::getParamsStructure($with, $order_by, $select, $where); // we generate the structure with the data that we receive
        self::processParamsStructure($clause, $struct);
        if ($where) {
            self::applyWhereConditionsToStructure($clause, $struct['where_conditions']);
        }
    }   

    /**
     * Create an array processing params.
     */

    // PONER PRIVATE
    public static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null, ?string $string_where = null): array {
        $struct = [];

        if ($string_with) {
            // process $string_with --> skeleton of $struct
            foreach (explode(',', $string_with) as $with_segment) {
                $current = &$struct;
                foreach (explode('..', $with_segment) as $relation) {
                    if (!isset($current['with'][$relation])) {
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
                if (!str_contains($order_by_segment, '.')) {
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
                            if (!array_key_exists($key, $current)) {
                                throw new Exception("You can't order by field that are not in the relation.");
                            }

                            $current[$key]['order_by'] = [
                                $order_by => $order_by_direction,
                            ];
                        } else {
                            if (!array_key_exists($relation_path, $current)) {
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
                if (!str_contains($select_segment, '.')) {
                    $current = &$struct;
                    $current['select'] = explode('|', $select_segment);
                } else {
                    $current = &$struct['with'];
                    foreach (explode('..', $select_segment) as $relation_path) {
                        if (str_contains($relation_path, '.')) {
                            [$key, $select] = explode('.', $relation_path, 2);
                            if (!array_key_exists($key, $current)) {
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current[$key]['select'] = explode('|', $select);
                        } else {
                            if (!array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't select field that are not in the relation."); // esto da error
                            }

                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        if ($string_where) {
            foreach(StringDelimitersHelper::explodeOutsideRanges(',', $string_where) as $where_segment) { // separamos condiciones separadas por ',' en ppio estas son diferemtes where() encadenados regentados por el path
                $filter_type = self::getFilterType($where_segment); // first, clean the segment

                $or_conditions = StringDelimitersHelper::explodeOutsideRanges('||', $where_segment); // explotamos por || buscando condiciones OR
                if (count($or_conditions) > 1) {
                    $condition_group = [
                        'or_group' => true,
                        'filter_type' => $filter_type,
                        'groups' => [],
                    ];

                    foreach($or_conditions as $or_condition) { // para cada condición OR
                        $and_conditions = StringDelimitersHelper::explodeOutsideRanges('&&', $or_condition); // explotamos por && en busca de condiciones AND, si las encontramos deberemos unirlas

                        $and_group = []; // * Definimos el grupo AND para ir llenándolo dentro del foreach con los diferentes segmentos AND encontrados
                        foreach($and_conditions as $condition) {
                            $and_group[] = StructHelper::createConditionArray($condition); // habiéndo llegado a la condición simple, transformamos el string en un pequeño array con clave: key, operator, value, path
                        }

                        $condition_group['groups'][] = [
                            'conditions' => $and_group
                        ];
                    }

                    $struct['where_conditions'][] = $condition_group; // una vez hemos procesado todo el bloque encontrado entre coma y coma, añadimos el grupo de condiciones al $struct general
                } else {
                    $struct['where_conditions'][] = [
                        'filter_type' => $filter_type,
                        'condition' => StructHelper::createConditionArray($where_segment),
                    ];
                }
            }
            // foreach(StringDelimitersHelper::explodeOutsideRanges(',', $string_where) as $where_segment) {
            //     // si el filter_type es parent o both, extraigo el relation path
            //     $filter_type = self::getFilterType($where_segment); // first, clean the segment
            //     $full_relation_path = null;
            //     if ($filter_type->isParentOrBoth()) {
            //         $full_relation_path = StructHelper::extractRelationPathFromWhereSegment($where_segment);
            //     }
                
            //     foreach(StringDelimitersHelper::explodeOutsideRanges('..', $where_segment) as $relation_path) {
            //         //tengo que mirar si $relation_path contiene . para saber si tengo que procesar la condición
            //         $parts = StringDelimitersHelper::explodeOutsideRanges('.', $relation_path); // lo hacemos de esta manera porqué no nos interesa tener en cuenta puntos que se encuentren dentro de los delimitadores
            //         if (count($parts) > 1) {
            //             // estoy en el nivel de la relación adecuado
            //         } else {
            //             // estoy en un simple string
            //     }
            //         }
            //     // aquí tengo que incluir las condiciones en el struct
            // }

        }

        return $struct;
    }

    // Extract de filter type to apply on condition
    public static function getFilterType(string &$where_segment): FilterType {
        $parts = StringDelimitersHelper::explodeOutsideRanges('::', $where_segment);

        if(count($parts) === 1) {
            return FilterType::Parent; // por defecto padre mejor
        }

        $filter_type = array_pop($parts);
        $where_segment = implode('::', $parts);

        return FilterType::from($filter_type);
    }

    /**
     * Process the params structure.
     */
    private static function processParamsStructure(mixed &$clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void {
        // SELECT
        if (!empty($struct['select'])) {
            $clause->select(StructHelper::buildSelectRequiredFields($struct['select'], $parent_model, $relation));
        }

        // ORDER BY
        if (!empty($struct['order_by'])) {
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

    private static function applyWhereConditionsToStructure(mixed &$clause, array $where_conditions): void {
        foreach($where_conditions as $condition_group) {
            $filter_type = $condition_group['filter_type']; // guardamos el filter_type de la condición

            if (!empty($condition_group['or_group'])) {
                // estamos en una condición OR
                $clause->where(function ($q) use ($condition_group, $filter_type) {

                    foreach($condition_group['groups'] as $and_group) { // iteramos sobre los grupos or
                        $q->orWhere(function ($sub_q) use ($and_group, $filter_type) {

                            foreach ($and_group['conditions'] as $condition) { // iteramos sobre cada condición and
                                self::processSimpleCondition($sub_q, $condition, $filter_type); // tene
                            }
                        });
                    }
                });
            } else {
                // estamos en una condición AND
                self::processSimpleCondition($clause, $condition_group['condition'], $filter_type);
            }
        }
    }

    /**
     * Process simple condition ['key', 'operator', 'value', 'path']
     */
    private static function processSimpleCondition(&$query, array $condition, FilterType $filter_type): void
    {
        $has_path = !empty($condition['path']);
        $relation_path = $has_path ? str_replace('..', '.', $condition['path']) : null;

        if (!$has_path) {
            self::processConditionOperator($query, $condition);
            return;
        }

        switch ($filter_type) {
            case FilterType::Parent:
                // Filtra el padre por el valor de los hijos
                $query->whereHas($relation_path, function ($q) use ($condition) { // project_task_boards..project_task_board_time_budgets..working_times
                    self::processConditionOperator($q, $condition);
                });
                $query->with($relation_path); // --> esto sobreescribe las relaciones cargadas anteriormente
                break;
    
            case FilterType::Child:
                // Sólo devuelve los hijos que cumplen con la condición
                $query->with([$relation_path => function ($q) use ($condition) {
                    self::processConditionOperator($q, $condition);
                }]);
                break;

                // lo único que cambia entre el filtro de padres e hijos es la manera en la que se hace el with y que en el filter parent se aplica el whereHas
                // podemos hacer que filter_both ejecute ambos bloques y así no necesitamos poner explícitamente un case de filter_both
    
            case FilterType::Both:
                // Devuelve los padres que cumplen la condición y los hijos que también la cumplan
                $query->whereHas($relation_path, function ($q) use ($condition) {
                    self::processConditionOperator($q, $condition);
                })->with([$relation_path => function ($q) use ($condition) {
                    self::processConditionOperator($q, $condition);
                }]);
                break;
    
            default:
                throw new Exception("Unsupported filter type: {$filter_type->value}");
        }
    }

    /**
     * Process diferent operators and build respective query
     */
    private static function processConditionOperator(&$query, array $condition) {
        $key = $condition['key'];
        $operator = $condition['operator'];
        $value = trim($condition['value'], '<{}>');

        switch (true) {
            case $operator === '=':
            case $operator === '!=':
            case $operator === '>':
            case $operator === '<':
            case $operator === '>=':
            case $operator === '<=':
            case $operator === 'like':
            case $operator === 'notLike':
                $query->where($key, $operator, $value);
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
                $real_operator = substr($operator, 5);
                $query->whereDate($key, $real_operator, $value);
                break;

            default:
                throw new Exception("Unsupported operator: {$operator}");
        }
    }

    /**
     * Transforms multiple values from string to array
     */
    public static function parseMultipleValues(string $raw_value, string $separator = '|'): array
    {
        return array_filter(
            array_map('trim', explode($separator, $raw_value)),
            fn($v) => $v !== ''
        );
    }
}
