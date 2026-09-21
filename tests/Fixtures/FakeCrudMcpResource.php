<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Tests\Fixtures;

use Bisual\LaravelShortcuts\CrudRepository;
use Bisual\LaravelShortcuts\Mcp\CrudMcpResource;
use Illuminate\Database\Eloquent\Model;

final class FakeModel extends Model
{
    protected $table = 'fake_models';
}

/**
 * @extends CrudRepository<FakeModel>
 */
final class FakeModelRepository extends CrudRepository
{
    public static $model = FakeModel::class;
}

/**
 * @extends CrudMcpResource<FakeModel, FakeModelRepository>
 */
final class FakeCrudMcpResource extends CrudMcpResource
{
    public static string $model = FakeModel::class;

    public static string $repository = FakeModelRepository::class;

    public static ?array $only = ['index', 'show'];

    public static array $abilities = [
        'index' => 'index',
    ];
}
