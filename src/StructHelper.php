<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class StructHelper
{
    /**
     * Build the select required fomat and fields.
     */
    public static function buildSelectRequiredFields(array $select_fields, ?Model $parent_model = null, ?string $relation = null): array
    {
        return array_unique(array_merge( // array_unique if we get the id from the front
            ['id'],
            $select_fields,
            $parent_model && $relation ? self::getForeignKeyData($parent_model, $relation) : []
        ));
    }

    // Get simple condition and returns:
    // [
    //    'key' => 'users..profiles.role',
    //    'operator' => '=',
    //    'value' => '<{manager}>',
    //    'path' => 'users..profiles'
    // ]
    public static function createConditionArray(string $condition): array
    {
        $path = StructHelper::extractRelationPathFromWhereSegment($condition);
        $cond = StructHelper::buildWhereCondition($condition);
        $cond['path'] = $path;

        return $cond;
    }

    /**
     * Return the relation path from where condition.
     */
    private static function extractRelationPathFromWhereSegment(string $where_segment): ?string // profile..users.code[=]<{900}>
    {$key = self::extractFullKeyFromWhereSegment($where_segment); // profile..users.code

        $last_dot = strrpos($key, '.');

        // we hare on the surface
        if ($last_dot === false) {
            return null;
        }

        $path = substr($key, 0, $last_dot);

        return $path; // profile..users
    }

    /**
     * Build where condition skeleton.
     */
    private static function buildWhereCondition(string $segment): array
    {
        if (! str_contains($segment, '[') || ! str_contains($segment, ']')) { // TODO: controlar que no estén dentro de los delimiters
            throw new Exception("Invalid where condition format: '{$segment}'");
        }
        [$key, $rest] = explode('[', $segment, 2);
        [$operator, $value] = explode(']', $rest, 2);

        return [
            'key' => self::getTargetColumnFromPath($key),
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * Return the key from where condition.
     */
    private static function extractFullKeyFromWhereSegment(string $where_segment): string // profile..users.code[=]<{900}>
    {[$key] = explode('[', $where_segment, 2);
        if (! isset($key) || trim($key) === '') {
            throw new Exception("Invalid where segment: missing key in '{$where_segment}'");
        }

        return $key; // profile..users.code
    }

    /**
     * Return the target column from path
     */
    private static function getTargetColumnFromPath(string $where_path): string // profile..details.code
    {$parts = explode('.', $where_path);

        return array_pop($parts); // code
    }

    /**
     * Get the $model->$relation foreign key data.
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
            return [$relation_instance->getForeignKeyName()]; // TODO: falta añadir el nombre de la tabla delante!!!
        }

        return [];
    }

    /**
     * GRAVEYARD
     */
    /**
    * Put a condition block into the structure.
    */
    // public static function injectConditionsToStruct(string $where_segment, array $conditions, array &$current) {
    //     if (count($conditions) > 1) {
    //         $current['where_or'] = array_map(function ($or_condition) {
    //             return self::buildWhereCondition($or_condition);
    //         }, $conditions);
    //     } else {
    //         $current['where'][] = self::buildWhereCondition($conditions[0]);
    //     }
    // }

    /**
     * Desestructurate a condition and put it on the struct
     */
    // public static function injectConditionsToStruct(string $where_condition, array &$current, bool $or_condition = false) {
    //     // 1. separamos por coma las condiciones
    //     // 2. separamos las $where_conditions por || para saber si hay $or_conditions y enviamos al inject
    //     // 3. dentro del inject, extraemos la clave de la condition y miramos si contiene '..' para saber en que nivel nos encontramos
    //     // 4. costruimos las condiciones con el build
    //     foreach(StringDelimitersHelper::explodeOutsideRanges('..', $where_condition) as $relation_path) {
    //         if (str_contains(StructHelper::extractKeyFromWhereSegment($relation_path), '.')) {
    //             [$key, $where_string] = StringDelimitersHelper::explodeOutsideRanges('.', $relation_path);
    //             if (!array_key_exists($key, $current)) {
    //                 throw new Exception("You can't apply conditions on field that are not in the relation.");
    //             }

    //             if ($or_condition) {
    //                 $current['where_or'][] = self::buildWhereCondition($where_string);
    //             } else {
    //                 $current['where'][] = self::buildWhereCondition($where_string);
    //             }
    //         } else {
    //             if (!array_key_exists($relation_path, $current)) {
    //                 throw new Exception("You can't apply conditions on field that are not in the relation.");
    //             }

    //             $current = &$current[$relation_path]['with'];
    //         }
    //     }
    // }
}
