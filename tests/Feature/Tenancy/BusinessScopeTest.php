<?php

use App\Models\Business;
use App\Support\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Support\Concerns\BelongsToBusiness;

/*
|--------------------------------------------------------------------------
| Global scope
|--------------------------------------------------------------------------
|
| Exercised against a throwaway table so the guarantee is proven now, before
| any real business-scoped table exists to prove it with.
|
*/

beforeEach(function () {
    Schema::create('scope_probes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id');
        $table->string('label');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('scope_probes');
});

function probeModel(): Model
{
    return new class extends Model {
        use BelongsToBusiness;

        protected $table = 'scope_probes';
        protected $fillable = ['business_id', 'label'];
    };
}

it('hides rows belonging to another business', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    probeModel()->newQuery()->create(['business_id' => $a->id, 'label' => 'a-row']);
    probeModel()->newQuery()->create(['business_id' => $b->id, 'label' => 'b-row']);

    app(CurrentBusiness::class)->set($a);

    $rows = probeModel()->newQuery()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->label)->toBe('a-row');
});

it('stamps business_id on create without the caller passing it', function () {
    $business = Business::factory()->create();
    app(CurrentBusiness::class)->set($business);

    $row = probeModel()->newQuery()->create(['label' => 'implicit']);

    expect($row->business_id)->toBe($business->id);
});

it('sees every business when no business context is set', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    probeModel()->newQuery()->create(['business_id' => $a->id, 'label' => 'a-row']);
    probeModel()->newQuery()->create(['business_id' => $b->id, 'label' => 'b-row']);

    app(CurrentBusiness::class)->clear();

    expect(probeModel()->newQuery()->count())->toBe(2);
});

it('suspends the scope only inside an explicit withoutScope block', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    probeModel()->newQuery()->create(['business_id' => $a->id, 'label' => 'a-row']);
    probeModel()->newQuery()->create(['business_id' => $b->id, 'label' => 'b-row']);

    $current = app(CurrentBusiness::class);
    $current->set($a);

    $all = $current->withoutScope(fn () => probeModel()->newQuery()->count());

    expect($all)->toBe(2)
        // …and the scope is restored afterwards, so the escape hatch cannot leak.
        ->and(probeModel()->newQuery()->count())->toBe(1);
});
