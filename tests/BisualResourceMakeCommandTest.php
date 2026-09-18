<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

const BISUAL_RESOURCE_TEST_NAME = 'MergeSmokeResource';

/**
 * @return list<string>
 */
function bisualResourceGeneratedPaths(): array
{
    return [
        app_path('Models/'.BISUAL_RESOURCE_TEST_NAME.'.php'),
        app_path('Repositories/'.BISUAL_RESOURCE_TEST_NAME.'Repository.php'),
        app_path('DTOs/Store'.BISUAL_RESOURCE_TEST_NAME.'DTO.php'),
        app_path('DTOs/Update'.BISUAL_RESOURCE_TEST_NAME.'DTO.php'),
        app_path('Http/Requests/Store'.BISUAL_RESOURCE_TEST_NAME.'Request.php'),
        app_path('Http/Requests/Update'.BISUAL_RESOURCE_TEST_NAME.'Request.php'),
        app_path('Http/Controllers/API/'.BISUAL_RESOURCE_TEST_NAME.'Controller.php'),
        app_path('Policies/'.BISUAL_RESOURCE_TEST_NAME.'Policy.php'),
        database_path('factories/'.BISUAL_RESOURCE_TEST_NAME.'Factory.php'),
        database_path('seeders/'.BISUAL_RESOURCE_TEST_NAME.'Seeder.php'),
    ];
}

function removeBisualResourceGeneratedFiles(): void
{
    foreach (bisualResourceGeneratedPaths() as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    foreach (glob(database_path('migrations/*_create_merge_smoke_resources_table.php')) ?: [] as $migration) {
        unlink($migration);
    }
}

beforeEach(fn () => removeBisualResourceGeneratedFiles());
afterEach(fn () => removeBisualResourceGeneratedFiles());

it('creates a complete Bisual Resource from the command line', function (): void {
    $exitCode = Artisan::call('make:bisual-resource', [
        'name' => BISUAL_RESOURCE_TEST_NAME,
        '--all' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);

    foreach (bisualResourceGeneratedPaths() as $path) {
        expect($path)->toBeFile();
    }

    $migrations = glob(database_path('migrations/*_create_merge_smoke_resources_table.php')) ?: [];
    expect($migrations)->toHaveCount(1);

    $repository = file_get_contents(app_path('Repositories/'.BISUAL_RESOURCE_TEST_NAME.'Repository.php'));
    $controller = file_get_contents(app_path('Http/Controllers/API/'.BISUAL_RESOURCE_TEST_NAME.'Controller.php'));

    expect($repository)
        ->toContain('extends CrudRepository')
        ->toContain('MergeSmokeResource::class')
        ->and($controller)
        ->toContain('extends CrudController')
        ->toContain('StoreMergeSmokeResourceRequest::class')
        ->toContain('UpdateMergeSmokeResourceRequest::class');
});
