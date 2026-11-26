<?php

namespace App\Domain\Stock\Audits\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Domain\Stock\Actions\ResolveStockCount;
use App\Domain\Stock\Audits\CompanyAuditReport;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\CompanyAudit;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What happens to a company audit once counting stops.
 *
 * Finishing hands it to the owner. The owner approves it — every difference
 * becomes a stock adjustment, through the same action the stock audit uses —
 * or closes it with the records kept as they were, or sends it back to be
 * counted again. Only approval moves stock.
 */
class DecideCompanyAudit
{
    public function __construct(
        private readonly ResolveStockCount $resolve,
        private readonly CompanyAuditReport $report,
        private readonly LedgerPoster $poster,
    ) {}

    public function submit(CompanyAudit $audit, User $by): CompanyAudit
    {
        $this->expect($audit, CompanyAudit::COUNTING, 'This audit has already been finished.');

        if (! $audit->items()->whereNotNull('stock_count_id')->exists()) {
            throw new RuntimeException('Count at least one product before finishing.');
        }

        $audit->forceFill([
            'status' => CompanyAudit::SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $by->id,
        ])->save();

        return $audit;
    }

    /** Back to the rack, before anything is decided. */
    public function reopen(CompanyAudit $audit): CompanyAudit
    {
        $this->expect($audit, CompanyAudit::SUBMITTED, 'Only an audit waiting for approval can be sent back.');

        $audit->forceFill(['status' => CompanyAudit::COUNTING, 'submitted_at' => null, 'submitted_by' => null])->save();

        return $audit;
    }

    public function approve(CompanyAudit $audit, User $by, ?string $reason = null): CompanyAudit
    {
        $this->expect($audit, CompanyAudit::SUBMITTED, 'Only an audit waiting for approval can be approved.');

        return DB::transaction(function () use ($audit, $by, $reason) {
            // Valued before the counts are resolved, at the prices each
            // product had when it was counted — the figures on the report.
            $summary = $this->report->summary($audit);
            $name = $audit->company->name;

            foreach ($this->openCounts($audit) as $count) {
                $this->resolve->approve($count, $by, $reason ?? "Company audit of {$name}", post: false);
            }

            // Counts that matched in total can still have found undated stock
            // under a real batch: that goes onto the batch, quantity unchanged.
            StockCount::query()
                ->whereIn('id', $audit->items()->whereNotNull('stock_count_id')->select('stock_count_id'))
                ->where('status', StockCount::AGREED)
                ->whereHas('lines', fn ($q) => $q->where('is_new', true))
                ->with('product', 'lines')
                ->get()
                ->each(fn (StockCount $count) => $this->resolve->redate($count, $by));

            /*
             * Into the books as one stock adjustment for the rack: what was
             * short is a loss (Stock Variance, off net profit), what was over
             * goes back into stock. Both sides kept apart, so the ledger
             * says what the report says.
             */
            $short = $summary['short']->absolute();
            $extra = $summary['extra'];
            $lines = [];

            if ($short->isPositive()) {
                $lines[] = PostingLine::debit(AccountCode::StockVariance, $short, "Short on audit of {$name}");
                $lines[] = PostingLine::credit(AccountCode::Stock, $short);
            }

            if ($extra->isPositive()) {
                $lines[] = PostingLine::debit(AccountCode::Stock, $extra);
                $lines[] = PostingLine::credit(AccountCode::StockVariance, $extra, "Over on audit of {$name}");
            }

            $transaction = $lines === [] ? null : $this->poster->post(new PostingSpec(
                business: $audit->business,
                date: $audit->business->today(),
                type: TransactionType::StockAdjustment,
                lines: $lines,
                createdBy: $by,
                narration: sprintf('Company audit — %s: short %s, over %s', $name, $short->format(), $extra->format()),
                source: $audit,
                reason: $reason ?? "Company audit of {$name}, counted ".$audit->business_date->format('j M Y'),
            ));

            $audit->forceFill(['transaction_id' => $transaction?->id])->save();

            return $this->decide($audit, CompanyAudit::APPROVED, $by, $reason);
        });
    }

    public function close(CompanyAudit $audit, User $by, ?string $reason = null): CompanyAudit
    {
        $this->expect($audit, CompanyAudit::SUBMITTED, 'Only an audit waiting for approval can be closed.');

        return DB::transaction(function () use ($audit, $by, $reason) {
            foreach ($this->openCounts($audit) as $count) {
                $this->resolve->dismiss($count, $by, $reason ?? 'Company audit closed with the records kept');
            }

            return $this->decide($audit, CompanyAudit::CLOSED, $by, $reason);
        });
    }

    private function openCounts(CompanyAudit $audit)
    {
        return StockCount::query()
            ->whereIn('id', $audit->items()->whereNotNull('stock_count_id')->select('stock_count_id'))
            ->where('status', StockCount::FOUND)
            ->with('product', 'lines')
            ->get();
    }

    private function decide(CompanyAudit $audit, string $status, User $by, ?string $reason): CompanyAudit
    {
        $audit->forceFill([
            'status' => $status,
            'decided_at' => now(),
            'decided_by' => $by->id,
            'note' => $reason ?: $audit->note,
        ])->save();

        return $audit;
    }

    private function expect(CompanyAudit $audit, string $status, string $message): void
    {
        if ($audit->status !== $status) {
            throw new RuntimeException($message);
        }
    }
}
