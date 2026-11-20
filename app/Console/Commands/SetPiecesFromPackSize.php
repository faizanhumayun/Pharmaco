<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\CompanyProduct;
use App\Support\PackSize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets "pieces in one pack" from the printed pack size, for pharmacies.
 *
 * A pharmacy opens packs and sells what is inside, so every product whose pack
 * size says how many are inside ("1/10", "20's") needs that number before the
 * counter can sell loose. New and edited products get it as they are saved;
 * this brings a catalogue that already existed into line.
 *
 * Only products still at one piece are touched, so a figure someone set by
 * hand is left alone, and so is anything already sold loose. Distributors are
 * skipped: they sell whole packs.
 */
class SetPiecesFromPackSize extends Command
{
    protected $signature = 'products:pieces-from-pack-size {business? : slug; every pharmacy when left out}
                            {--dry-run : show what would change and stop}';

    protected $description = 'Set pieces per pack from the printed pack size, for pharmacy businesses';

    public function handle(): int
    {
        $businesses = Business::query()
            ->when($this->argument('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get()
            ->filter(fn (Business $b) => $b->sellsLoose());

        if ($businesses->isEmpty()) {
            $this->warn('No pharmacy business matched. Distributors sell whole packs and are skipped.');

            return self::SUCCESS;
        }

        foreach ($businesses as $business) {
            $changes = CompanyProduct::forBusiness($business)
                ->where('units_per_pack', 1)
                ->whereNotNull('pack_size')
                ->get(['id', 'brand_name', 'pack_size'])
                ->map(fn (CompanyProduct $p) => ['id' => $p->id, 'name' => $p->brand_name, 'pack' => $p->pack_size, 'pieces' => PackSize::pieces($p->pack_size)])
                ->filter(fn (array $row) => $row['pieces'] > 1);

            $this->line("{$business->name}: {$changes->count()} products to set.");

            foreach ($changes->groupBy('pieces')->sortKeys() as $pieces => $group) {
                $this->line(sprintf('  %4d pieces  ×%-4d e.g. %s (%s)', $pieces, $group->count(), $group->first()['name'], $group->first()['pack']));
            }

            if ($this->option('dry-run') || $changes->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($business, $changes) {
                foreach ($changes->groupBy('pieces') as $pieces => $group) {
                    CompanyProduct::forBusiness($business)->whereIn('id', $group->pluck('id'))->update(['units_per_pack' => $pieces]);
                }

                activity()
                    ->performedOn($business)
                    ->withProperties(['products' => $changes->count(), 'by_pieces' => $changes->groupBy('pieces')->map->count()->all()])
                    ->event('products.pieces_set')
                    ->log('Pieces per pack set from the printed pack size');
            });

            $this->info('  Set.');
        }

        return self::SUCCESS;
    }
}
