<?php

namespace App\Domain\Stock\Audits\Actions;

use App\Domain\Stock\StockLedger;
use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use RuntimeException;

/**
 * Takes a line off a company audit's sheet.
 *
 * Only a line with nothing to account for: no stock in the records and not
 * yet counted. Such a line is on the sheet only because the whole catalogue
 * was listed; it carries no figure, so taking it off loses nothing. A line
 * with stock is deleted by counting it as nothing on the rack instead, so
 * that the loss is shown and booked.
 */
class RemoveAuditItem
{
    public function __construct(private readonly StockLedger $stock) {}

    public function handle(CompanyAudit $audit, CompanyAuditItem $item): void
    {
        if (! $audit->isCounting()) {
            throw new RuntimeException('This audit has been finished. Send it back for a recount to change the sheet.');
        }

        if ($item->company_audit_id !== $audit->id) {
            throw new RuntimeException('That product is not on this audit.');
        }

        if ($item->stock_count_id !== null) {
            throw new RuntimeException('This product has been counted. Change its count instead.');
        }

        $business = $audit->business;

        if ($this->stock->onHand($business, $item->product) !== 0 || $this->stock->looseOnHand($business, $item->product) !== 0) {
            throw new RuntimeException('The records hold stock of this product. Count it as nothing on the rack to delete that stock.');
        }

        $item->delete();
    }
}
