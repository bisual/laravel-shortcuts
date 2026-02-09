<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

abstract class CrudRepository
{
    /** @var class-string<Model> */
    public static string $model = Model::class;

    /*+
     * @params
     *      - with
     *      - without
     *      - ... other attributes to filter
     */
    public static function index(array $params = [], bool $paginate = false, ?callable $functionExtraParametersTreatment = null): LengthAwarePaginator|Collection
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

            if (is_callable($functionExtraParametersTreatment)) {
                $functionExtraParametersTreatment($clause, $params);
            }

            $whereClause = [];
            if (count($params) > 0) {
                $record = (new static::$model);

                foreach ($params as $attr => $val) {
                    if ($val !== null && $val !== '') {
                        if (str_contains((string) $attr, '-')) {
                            $separate = explode('-', (string) $attr);
                            $relations = implode('-', array_slice($separate, 0, -1));
                            $attribute = $separate[count($separate) - 1];
                            $table = (new static::$model)->{$relations}()->getRelated()->getTable();
                            $clause->whereHas($relations, function (Builder $q) use ($attribute, $val, $table): void {
                                if ($val === null || $val === 'null') {
                                    $q->whereNull($table.'.'.$attribute);
                                } elseif ($val === 'notnull') {
                                    $q->whereNotNull($table.'.'.$attribute);
                                } elseif (str_contains((string) $val, ',')) {
                                    $q->whereIn($table.'.'.$attribute, explode(',', (string) $val));
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
                            $clause->whereIn($attr, explode(',', (string) $val));
                        } elseif (is_numeric($val) || is_bool($val) || $val === 'false' || $val === 'true') {
                            $whereClause[] = [$attr, $val];
                        } elseif ($record->hasCast($attr, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])) {
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
                foreach (explode(',', (string) $without) as $w) {
                    $clause->without($w);
                }
            }

            // Process Searchable Fields
            if ($search) {
                $clause->where(function (Builder $query) use ($searchable_fields, $search): void {
                    foreach ($searchable_fields as $idx => $search_field) {
                        $parts = explode('.', $search_field);
                        if (count($parts) === 2) {
                            if ($idx === 0) {
                                $query->whereHas($parts[0], function (Builder $query) use ($parts, $search): void {
                                    $query->where($parts[1], 'like', "%{$search}%");
                                });
                            } else {
                                $query->orWhereHas($parts[0], function (Builder $query) use ($parts, $search): void {
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

            $records = $clause
                ->when($paginate, function (Builder $query) use ($perPage, $page): LengthAwarePaginator {
                    return $query->paginate($perPage, ['*'], 'page', $page);
                })
                ->when($limit, function (Builder $query) use ($limit): Builder {
                    return $query->limit($limit);
                })
                ->get();

            if ($append !== null) {
                foreach ($records as $record) {
                    foreach (explode(',', $append) as $append_item) {
                        $record->append($append_item);
                    }
                }
            }

            return $records;
        }

        if (is_callable($functionExtraParametersTreatment)) {
            $clause = (static::$model)::query();

            if (is_callable($functionExtraParametersTreatment)) {
                $functionExtraParametersTreatment($clause, $params);
            }

            return $paginate
                ? $clause->paginate($perPage, ['*'], 'page', $page)
                : $clause->get();
        }

        return $paginate
            ? (static::$model)::query()->paginate($perPage, ['*'], 'page', $page)
            : (static::$model)::query()->get();
    }

    public static function show(int|string|Model $id, array $params = [], ?callable $functionExtraParametersTreatment = null, bool $withoutGlobalScopes = false): Model
    {
        // handling with, order_by and select
        $clause = self::getClause($params, $withoutGlobalScopes);

        if (is_callable($functionExtraParametersTreatment)) {
            $functionExtraParametersTreatment($clause, $params);
        }

        $idIsModel = $id instanceof Model && $id::class === static::$model;

        if ($idIsModel) {
            return $id;
        }

        $clause->where((new static::$model)->getKeyName(), $id);

        $record = $clause->firstOrFail();

        if (isset($params['append']) && $params['append'] !== '') {
            foreach (explode(',', (string) $params['append']) as $append) {
                $record->append($append);
            }
        }

        return $record;
    }

    public static function store(array $data): Model
    {
        return (static::$model)::query()->create($data);
    }

    public static function update(int|string|Model $id, array $data): Model
    {
        $record = self::show($id);

        $record->update($data);

        return $record->fresh();
    }

    public static function destroy(int|string|Model $record, ?callable $functionExtraParametersTreatment = null): Model
    {
        $record = self::show($record);

        if (is_callable($functionExtraParametersTreatment)) {
            $functionExtraParametersTreatment($record->id);
        }

        $record->delete();

        return $record;
    }

    protected static function getClause(array &$params = [], bool $withoutGlobalScopes = false): Builder
    {
        $query = (static::$model)::query()
            ->when($withoutGlobalScopes, function (Builder $q): Builder {
                return $q->withoutGlobalScopes();
            });

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
            self::handleWithOrderByAndSelect($query, $with, $order_by, $select);
        }

        return $query;
    }

    private static function handleWithOrderByAndSelect(Builder &$clause, ?string $with = null, ?string $order_by = null, ?string $select = null): void
    {
        $struct = self::getParamsStructure($with, $order_by, $select); // we generate the structure with the data that we receive
        self::processParamsStructure($clause, $struct);
    }

    private static function processParamsStructure(Builder &$clause, array $struct, ?Model $parent_model = null, ?string $relation = null): void
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

        // recursuvity stop condition
        if (empty($struct['with'])) {
            return;
        }

        foreach ($struct['with'] as $relation => $config) {
            $clause->with($relation, function (Builder $query) use ($relation, $config, $clause): void {
                $parent_model = $clause->getModel(); // get the parent model
                self::processParamsStructure($query, $config, $parent_model, $relation);
            });
        }
    }

    /**
     * Create an array processing params.
     */
    private static function getParamsStructure(?string $string_with = null, ?string $string_order_by = null, ?string $string_select = null): array
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

        return $struct;
    }

    private static function getForeignKeyData(Model $record, string $relation): array
    {
        if (! method_exists($record, $relation)) {
            throw new Exception("Relation '{$relation}' not found in model ".$record::class);
        }

        $relation_instance = $record->{$relation}();

        if (! $relation_instance instanceof Relation) {
            throw new Exception("Relation '{$relation}' not found in model ".$record::class);
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
    private static function buildSelectRequiredFields(array $select_fields, ?Model $parent_record = null, ?string $relation = null): array
    {
        return collect(['id'])
            ->concat($select_fields)
            ->concat($parent_record && $relation ? self::getForeignKeyData($parent_record, $relation) : [])
            ->unique()
            ->values()
            ->all();
    }
}
