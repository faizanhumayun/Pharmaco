<?php

namespace App\Domain\Stock\Audits\Actions;

use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use App\Models\CompanyProduct;
use RuntimeException;

/**
 * A product found on the rack that the sheet does not list.
 *
 * The sheet starts with what the records hold stock of, so a product the
 * records put at nothing is not on it — yet there it is on the shelf. It is
 * added here and then counted like any other line; whatever is found of it
 * is extra stock, booked when the audit is approved.
 */
class AddAuditItem
{
    public function handle(CompanyAudit $audit, CompanyProduct $product): CompanyAuditItem
    {
        if (! $audit->isCounting()) {
            throw new RuntimeException('This audit has been finished. Send it back for a recount to add a product.');
        }

        if ($product->business_id !== $audit->business_id) {
            throw new RuntimeException('That product belongs to another business.');
        }

        if ($product->company_id !== $audit->company_id) {
            throw new RuntimeException("{$product->label()} is in another company's catalogue. Count it in that company's audit.");
        }

        return CompanyAuditItem::firstOrCreate(
            ['company_audit_id' => $audit->id, 'company_product_id' => $product->id],
            ['business_id' => $audit->business_id],
        );
    }
}
