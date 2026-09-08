<?php

namespace Bisual\LaravelShortcuts\Traits;

use Ramsey\Uuid\Uuid;

trait HasUuid
{
    public static function bootHasUUID()
    {
        static::creating(function ($model) {
            $model->incrementing = false;
            $model->keyType = 'string';

            $uuidFieldName = $model->getUUIDFieldName();
            if (empty($model->$uuidFieldName)) {
                $model->$uuidFieldName = static::generateUUID();
            }
        });

        static::retrieved(function ($model) {
            $model->incrementing = false;
            $model->keyType = 'string';
        });
    }

    public function getUUIDFieldName()
    {
        if (! empty($this->uuidFieldName)) {
            return $this->uuidFieldName;
        }

        return 'uuid';
    }

    public static function generateUUID()
    {
        return Uuid::uuid4();
    }

    public function scopeByUUID($query, $uuid)
    {
        return $query->where($this->getUUIDFieldName(), $uuid);
    }

    public static function findByUuid($uuid)
    {
        return static::byUUID($uuid)->first();
    }
}
