<?php

namespace Bisual\LaravelShortcuts\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * @deprecated Use the Laravel hasUuid trait instead
 * @see https://laravel.com/docs/eloquent#uuid-and-ulid-keys
 */
trait HasUuid
{
    public static function bootHasUUID(): void
    {
        static::creating(function (Model $record): void {
            $record->incrementing = false;
            $record->keyType = 'string';

            $uuidFieldName = $record->getUUIDFieldName();
            if (empty($record->$uuidFieldName)) {
                $record->$uuidFieldName = static::generateUUID();
            }
        });

        static::retrieved(function (Model $record): void {
            $record->incrementing = false;
            $record->keyType = 'string';
        });
    }

    public function getUUIDFieldName(): string
    {
        if (! empty($this->uuidFieldName)) {
            return $this->uuidFieldName;
        }

        return 'uuid';
    }

    public static function generateUUID(): UuidInterface
    {
        return Uuid::uuid4();
    }

    public function scopeByUUID(Builder $query, string $uuid): Builder
    {
        return $query->where($this->getUUIDFieldName(), $uuid);
    }

    public static function findByUuid(string $uuid): ?Model
    {
        return static::byUUID($uuid)->first();
    }
}
