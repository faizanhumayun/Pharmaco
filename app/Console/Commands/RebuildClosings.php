<?php

namespace App\Console\Commands;

use App\Domain\Closing\DailyClosingCalculator;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Recomputes closing figures from the ledger.
 *
 * This command is what keeps daily_closings a cache rather than a second source
 * of truth. If a rebuild ever disagrees with a stored closing, that is a bug and
 * the difference is reported rather than silently overwritten.
 */
class RebuildClosings extends Command
{
    protected $signature = 'closings:rebuild
        {--business= : Slug of a single business}
        {--from= : Earliest business date to rebuild}
        {--check : Report differences without writing anything}';

    protected $description = 'Rebuild daily closing figures from the ledger';

    public function handle(DailyClosingCalculator $calculator): int
    {
        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('name')
            ->get();

        $drift = 0;
        $rebuilt = 0;

        foreach ($businesses as $business) {
            $closings = DailyClosing::forBusiness($business)
                ->when($this->option('from'), fn ($q, $from) => $q->where('business_date', '>=', $from))
                ->orderBy('business_date')
                ->get();

            foreach ($closings as $closing) {
                $figures = $calculator->compute($business, $closing->business_date);
                $differences = [];

                foreach ($figures as $key => $value) {
                    if (! $value->equals($closing->{$key})) {
                        $differences[$key] = "{$closing->{$key}->format()} → {$value->format()}";
                    }
                }

                if ($differences !== []) {
                    $drift++;
                    $this->line("  <fg=red>✗</> {$business->name} {$closing->business_date->toDateString()}");

                    foreach ($differences as $key => $change) {
                        $this->line("      <fg=red>{$key}: {$change}</>");
                    }
                }

                if (! $this->option('check') && $differences !== []) {
                    $closing->forceFill([
                        ...array_map(fn (Money $m) => $m->toDecimal(), $figures),
                        'built_at' => now(),
                    ])->save();
                    $rebuilt++;
                }
            }
        }

        $this->newLine();

        if ($drift === 0) {
            $this->info('Every stored closing matches the ledger exactly.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->error("{$drift} closings disagree with the ledger.");

            return self::FAILURE;
        }

        $this->warn("{$rebuilt} closings rebuilt. Investigate why they had drifted.");

        return self::SUCCESS;
    }
}
