<?php

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\Model;

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
                                if (is_numeric($val) || ($val == true || $val == false)) {
                                    $q->where($attribute, $val);
                                } else {
                                    $q->where($attribute, 'like', "%$val%");
                                }
                            });
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

            $data = $paginate ? $clause->paginate() : $clause->get();

            return $data;
        } elseif ($functionExtraParametersTreatment != null) {
            $clause = (static::$model)::query();
            if ($functionExtraParametersTreatment != null) {
                $functionExtraParametersTreatment($clause, $params);
            }

            return $paginate ? $clause->paginate() : $clause->get();
        } else {
        return $paginate ? (static::$model)::paginate() : (static::$model)::toSql();
        }
    }

    public static function show($id, array $params = [], $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false)
    {
        $clause = self::getClause($params, $withoutGlobalScopes);
        if ($functionExtraParametersTreatment != null) {
        $functionExtraParametersTreatment($clause, $params);
        }

        return $clause->where('id', $id)->firstOrFail();
    }

    public static function showByUuid($uuid, array $params = [], $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false)
    {
        $clause = self::getClause($params, $withoutGlobalScopes);
        if ($functionExtraParametersTreatment != null) {
        $functionExtraParametersTreatment($clause, $params);
        }

        return $clause->where((static::$model)::UUID, $uuid)->firstOrFail();
    }

    public static function store(array $data)
    {
        return (static::$model)::create($data);
    }

    public static function update($model, $params)
    {
        if (is_numeric($model)) {
        $model = self::show($model);
        } elseif (is_string($model)) {
        $model = self::showByUuid($model);
        }

        $model->update($params);

        return $model->fresh();
    }

    public static function destroy($model, $functionExtraParametersTreatment = null)
    {
        if (is_numeric($model)) {
        $model = self::show($model);
        } elseif (is_string($model)) {
        $model = self::showByUuid($model);
        }

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
