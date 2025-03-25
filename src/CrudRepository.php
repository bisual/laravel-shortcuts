<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

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
            // handling with, order_by and select
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
                $model_inst = (new static::$model());
                foreach ($params as $attr => $val) {
                    if ($val !== null && $val !== '') {
                        if (str_contains($attr, '-')) {
                            $separate = explode('-', $attr);
                            $relations = implode('-', array_slice($separate, 0, -1));
                            $attribute = $separate[count($separate) - 1];
                            $table = (new static::$model())->{$relations}()->getRelated()->getTable();
                            $clause->whereHas($relations, function ($q) use (&$attribute, &$val, &$table, &$model_inst): void {
                                if ($val === null || $val === 'null') {
                                    $q->whereNull($table.'.'.$attribute);
                                } elseif($val === 'notnull') {
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
                $clause->where(function ($query) use (&$searchable_fields, &$search): void {
                    foreach ($searchable_fields as $idx => $search_field) {
                        $parts = explode('.', $search_field);
                        if(count($parts) === 2) {
                            if($idx === 0) {
                                $query->whereHas($parts[0], function ($query) use (&$parts, &$search): void { $query->where($parts[1], 'like', "%{$search}%"); });
                            } else {
                                $query->orWhereHas($parts[0], function ($query) use (&$parts, &$search): void { $query->where($parts[1], 'like', "%{$search}%"); });
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

        if ($with || $order_by || $select) {
            self::handleWithOrderByAndSelect($clause, $with, $order_by, $select);
        }

        return $clause;
    }

    private static function handleWithOrderByAndSelect(&$clause, ?string $with = null, ?string $order_by = null, ?string $select = null): void {
        $struct = self::getParamsStructure($with, $order_by, $select); // we generate the structure with the data that we receive
        self::processParamsStructure($clause, $struct);
    }

    /**
     * Process the params structure.
     *
     * @param [type] $clause
     */
    private static function processParamsStructure(&$clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void {
        // SELECT
        if (!empty($struct['select'])) {
            $clause->select(self::buildSelectRequiredFields($struct['select'], $parent_model, $relation));
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

    /**
     * Create an array processing params.
     */
    private static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null): array {
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
                                throw new Exception("You can't select field that are not in the relation.");
                            }

                            $current[$key]['select'] = explode('|', $select);
                        } else {
                            if (!array_key_exists($relation_path, $current)) {
                                throw new Exception("You can't select field that are not in the relation.");
                            }

                            $current = &$current[$relation_path]['with'];
                        }
                    }
                }
            }
        }

        return $struct;
    }

    /**
     * Get the $model->$relation foreign key data.
     */
    private static function getForeignKeyData(Model $model, string $relation): array {
        if (!method_exists($model, $relation)) {
            throw new Exception("Relation '{$relation}' not found in model ".$model::class);
        }

        $relation_instance = $model->{$relation}();

        if (!$relation_instance instanceof Relation) {
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
     */
    private static function buildSelectRequiredFields(array $select_fields, ?Model $parent_model = null, ?string $relation = null): array {
        return array_unique(array_merge( // array_unique if we get the id from the front
            ['id'],
            $select_fields,
            $parent_model && $relation ? self::getForeignKeyData($parent_model, $relation) : []
        ));
    }
}
