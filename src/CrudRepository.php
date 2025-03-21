<?php

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

abstract class CrudRepository
{
    public static $model = Model::class;

    /**+
     * @params
     *      - with
     *      - without
     *      - ... other attributes to filter
     */
    public static function index(array $params = [], bool $paginate = false, $functionExtraParametersTreatment = null)
    {
        $perPage = $params['per_page'] ?? 15; // Obtener el número de elementos por página, predeterminado a 15
        unset($params['per_page']);
        $page = $params['page'] ?? 1; // Obtener el número de página, predeterminado a 1
        unset($params['page']);
        unset($params['total']);
        $limit = null;
        if (isset($params['limit'])) {
            $limit = $params['limit'];
            unset($params['limit']);
        }

        $select = null;

        if (count($params) > 0) {
            $clause = (static::$model)::query();

            if (isset($params['select'])) {
                $select = is_string($params['select']) ? explode(',', $params['select']) : $params['select'];
                unset($params['select']);
            }

            $searchable_fields = (new static::$model)->searchable;
            $search = null;
            if (isset($params['search']) && $searchable_fields != null && count($searchable_fields) > 0) {
                $search = $params['search'];
                unset($params['search']);
            }

            // With
            $with = null;
            if (array_key_exists('with', $params)) {
                $with = $params['with'];
                unset($params['with']);
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

            // Order by
            $orderBy = null;
            if (array_key_exists('order_by', $params)) {
                $orderBy = $params['order_by'];
                unset($params['order_by']);
            }

            // Order by direction
            $orderByDirection = null;
            if (array_key_exists('order_by_direction', $params)) {
                $orderByDirection = $params['order_by_direction'];
                unset($params['order_by_direction']);
            }

            // Append
            $append = null;
            if (array_key_exists('append', $params)) {
                $append = $params['append'];
                unset($params['append']);
            }

            // Extra parameters treatment
            if ($functionExtraParametersTreatment != null) {
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
                            $table = (new static::$model)->$relations()->getRelated()->getTable();
                            $clause->whereHas($relations, function ($q) use (&$attribute, &$val, &$table, &$model_inst) {
                                if ($val === null || $val === 'null') {
                                    $q->whereNull($table.'.'.$attribute);
                                } elseif (str_contains($val, ',')) {
                                    $q->whereIn($table.'.'.$attribute, explode(',', $val));
                                } elseif (is_numeric($val) || is_bool($val) || $val == 'false' || $val == 'true') {
                                    $q->where($table.'.'.$attribute, $val);
                                } else {
                                    $q->where($table.'.'.$attribute, 'like', "%$val%");
                                }
                            });
                        } elseif ($val === null || $val === 'null') {
                            array_push($whereClause, [$attr, null]); // $q->whereNull($attribute);
                        } elseif (str_contains($val, ',')) {
                            $clause->whereIn($attr, explode(',', $val));
                        } elseif (is_numeric($val) || is_bool($val) || $val == 'false' || $val == 'true') {
                            array_push($whereClause, [$attr, $val]);
                        } elseif ($model_inst->hasCast($attr, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])) {
                            $clause->whereDate($attr, Carbon::parse($val));
                        } else {
                            array_push($whereClause, [$attr, 'like', "%$val%"]);
                        }
                    }
                }
            }

            $clause = $clause->where($whereClause);

            // Process Scopes
            if ($scopes != null) {
                $scopes = explode(',', $scopes);
                foreach ($scopes as $scope) {
                    $scope_destruct = explode(':', $scope);
                    if (count($scope_destruct) > 0) {
                        $scope_method = array_shift($scope_destruct);
                        $scope_params = $scope_destruct;
                        $clause->$scope_method(...$scope_params);
                    }
                }
            }

            /**
             * Process With
             *  - array $with
             *      - relation.attribute
             *      - relation..relation2
             *      - relation..relation2.atribute
             */
            if ($with) {
                self::handleWith($clause, $with);
            }

            // Process Without
            if ($without) {
                foreach (explode(',', $without) as $w) {
                    $clause->without($w);
                }
            }

            // Process Searchable Fields
            if ($search) {
                $clause->where(function ($query) use (&$searchable_fields, &$search) {
                    foreach ($searchable_fields as $idx => $search_field) {
                        if ($idx == 0) {
                            $query->where($search_field, 'like', "%$search%");
                        } else {
                            $query->orWhere($search_field, 'like', "%$search%");
                        }
                    }
                });
            }

            // Process Order by
            if ($orderBy) {
                $clause->orderBy($orderBy, $orderByDirection ?? 'asc');
            }

            if ($select) {
                $clause->select($select);
            }

            if ($paginate) {
                $data = $clause->paginate($perPage, ['*'], 'page', $page);
            } else {
                if ($limit) {
                    $clause->limit($limit);
                }
                $data = $clause->get();
            }

            if ($append != null) {
                foreach ($data as $model) {
                    foreach (explode(',', $append) as $append_item) {
                        $model->append($append_item);
                    }
                }
            }

            return $data;
        } elseif ($functionExtraParametersTreatment != null) {
            $clause = (static::$model)::query();
            if ($functionExtraParametersTreatment != null) {
                $functionExtraParametersTreatment($clause, $params);
            }

            return $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();
        } else {
            return $paginate ? (static::$model)::paginate($perPage, ['*'], 'page', $page) : (static::$model)::get();
        }
    }

    public static function show($id, array $params = [], $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false)
    {
        $clause = self::getClause($params, $withoutGlobalScopes);
        if ($functionExtraParametersTreatment != null) {
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

        if (isset($params['with']) && $params['with'] != '') {
            self::handleWith($clause, $params['with']);
        }

        $model = $clause->firstOrFail();

        if (isset($params['append']) && $params['append'] != '') {
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

        if ($functionExtraParametersTreatment != null) {
            $functionExtraParametersTreatment($model->id);
        }

        $model->delete();

        return $model;
    }

    /**
     * Other private functions
     */
    protected static function getClause(array $params = [], bool $withoutGlobalScopes = false)
    {
        $clause = $withoutGlobalScopes ? (static::$model)::withoutGlobalScopes() : (static::$model)::query();
        if (isset($params['with'])) {
            $with = $params['with'];
            unset($params['with']);
            self::handleWith($clause, $with);
        }

        return $clause;
    }

    /**
     * Process With
     *  - array $with
     *      - relation.attribute
     *      - relation..relation2
     *      - relation..relation2.atribute
     */
    private static function handleWith(&$clause, string $with)
    {
        foreach (explode(',', $with) as $w) {
            $arr_w = explode('.', $w);
            if (! str_contains($w, '..') && count($arr_w) == 2) {
                $clause->with([$arr_w[0] => function ($q) use ($arr_w) {
                    // Esta la ID por esto: https://stackoverflow.com/questions/19852927/get-specific-columns-using-with-function-in-laravel-eloquent
                    $q->select('id', $arr_w[1]); // p.e. select 'user'.'user_uuid'
                }]);
            } else {
                $w_cleaned = str_replace('..', '.', $w);
                $clause->with($w_cleaned);
            }
        }
    }
}
