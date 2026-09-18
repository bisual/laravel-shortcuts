<?php

declare(strict_types=1);

use Bisual\LaravelShortcuts\CrudRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('morph_filter_entries', function (Blueprint $table): void {
        $table->id();
        $table->morphs('filterable');
        $table->timestamps();
    });

    Schema::create('morph_filter_active_targets', function (Blueprint $table): void {
        $table->id();
        $table->boolean('active');
        $table->timestamps();
    });

    Schema::create('morph_filter_plain_targets', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('morph_filter_entries');
    Schema::dropIfExists('morph_filter_active_targets');
    Schema::dropIfExists('morph_filter_plain_targets');
});

it('filters MorphTo parents only through types that contain the requested column', function (): void {
    $active = MorphFilterActiveTarget::query()->create(['active' => true]);
    $inactive = MorphFilterActiveTarget::query()->create(['active' => false]);
    $plain = MorphFilterPlainTarget::query()->create(['name' => 'No active column']);

    $matching = new MorphFilterEntry;
    $matching->filterable()->associate($active);
    $matching->save();

    $notMatching = new MorphFilterEntry;
    $notMatching->filterable()->associate($inactive);
    $notMatching->save();

    $differentType = new MorphFilterEntry;
    $differentType->filterable()->associate($plain);
    $differentType->save();

    $result = MorphFilterEntryRepository::index(['filterable.active' => true]);

    expect($result->modelKeys())->toBe([$matching->getKey()]);
});

it('filters and constrains an eager-loaded MorphTo relation', function (): void {
    $active = MorphFilterActiveTarget::query()->create(['active' => true]);
    $inactive = MorphFilterActiveTarget::query()->create(['active' => false]);

    $matching = new MorphFilterEntry;
    $matching->filterable()->associate($active);
    $matching->save();

    $notMatching = new MorphFilterEntry;
    $notMatching->filterable()->associate($inactive);
    $notMatching->save();

    $result = MorphFilterEntryRepository::index([
        'with' => 'filterable',
        'filterable.active' => true,
    ]);

    expect($result)
        ->toHaveCount(1)
        ->and($result->first()?->is($matching))->toBeTrue()
        ->and($result->first()?->relationLoaded('filterable'))->toBeTrue()
        ->and($result->first()?->filterable?->is($active))->toBeTrue();
});

final class MorphFilterEntry extends Model
{
    protected $guarded = [];

    public function filterable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class MorphFilterActiveTarget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}

final class MorphFilterPlainTarget extends Model
{
    protected $guarded = [];
}

/**
 * @extends CrudRepository<MorphFilterEntry>
 */
final class MorphFilterEntryRepository extends CrudRepository
{
    public static $model = MorphFilterEntry::class;
}
