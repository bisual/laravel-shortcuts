<?php

namespace Bisual\LaravelShortcuts;

use Bisual\LaravelShortcuts\Traits\HasUuid;
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

        if (count($params) > 0) {
            $clause = (static::$model)::query();

            // With
            $with = null;
            if (array_key_exists('with', $params)) {
                $with = $params['with'];
                unset($params['with']);
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

            // Extra parameters treatment
            if ($functionExtraParametersTreatment != null) {
                $functionExtraParametersTreatment($clause, $params);
            }

            $whereClause = [];
            if (count($params) > 0) {
                foreach ($params as $attr => $val) {
                    if ($val !== null && $val !== '') {
                        if (str_contains($attr, '.')) {
                            $separate = explode('.', $attr);
                            $relations = implode('.', array_slice($separate, 0, -1));
                            $attribute = $separate[count($separate) - 1];
                            $clause->whereHas($relations, function ($q) use ($attribute, $val) {
                                if ($val === null || $val === 'null') {
                                    $q->whereNull($attribute);
                                } elseif (is_numeric($val) || ($val === true || $val === false)) {
                                    $q->where($attribute, $val);
                                } else {
                                    $q->where($attribute, 'like', "%$val%");
                                }
                            });
                        } elseif ($val === null || $val === 'null') {
                            array_push($whereClause, [$attr, null]); // $q->whereNull($attribute);
                        } elseif (is_numeric($val) || is_bool($val)) {
                            array_push($whereClause, [$attr, $val]);
                        } else {
                            array_push($whereClause, [$attr, 'like', "%$val%"]);
                        }
                    }
                }
            }

            $clause = $clause->where($whereClause);

            // Process With
            if ($with) {
                foreach (explode(',', $with) as $w) {
                    $arr_w = explode('.', $w);
                    if (count($arr_w) == 2) {
                        $clause->with([$arr_w[0] => function ($q) use ($arr_w) {
                            // Esta la ID por esto: https://stackoverflow.com/questions/19852927/get-specific-columns-using-with-function-in-laravel-eloquent
                            $q->select('id', $arr_w[1]); // p.e. select 'user'.'user_uuid'
                        }]);
                    } else {
                        $clause->with($w);
                    }
                }
            }

            // Process Without
            if ($without) {
                foreach (explode(',', $without) as $w) {
                    $clause->without($w);
                }
            }

            // Process Order by
            if ($orderBy) {
                $clause->orderBy($orderBy, $orderByDirection ?? 'asc');
            }

            $data = $paginate ? $clause->paginate($perPage, ['*'], 'page', $page) : $clause->get();

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
            $clause->with(explode(',', $params['with']));
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
            $clause = $clause->with(explode(',', $with));
        }

        return $clause;
    }
}
