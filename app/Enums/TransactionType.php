<?php

namespace App\Enums;

/**
 * Every kind of business event the ledger can record.
 *
 * Capture surfaces produce these; they never invent their own. When
 * invoice-level entry replaces the summary daily form in a later phase, it
 * produces the same types with finer detail, and nothing downstream changes.
 */
enum TransactionType: string
{
    case Opening = 'OPENING';

    case PurchaseCredit = 'PURCHASE_CREDIT';
    case PurchaseCash = 'PURCHASE_CASH';
    case PurchaseReturn = 'PURCHASE_RETURN';

    case SaleCash = 'SALE_CASH';
    case SaleCredit = 'SALE_CREDIT';
    case SalesReturn = 'SALES_RETURN';
    case CostOfGoodsSold = 'COGS';

    case Collection = 'COLLECTION';
    case DiscountAllowed = 'DISCOUNT_ALLOWED';
    case BadDebt = 'BAD_DEBT';

    case CompanyPayment = 'COMPANY_PAYMENT';
    case DiscountReceived = 'DISCOUNT_RECEIVED';

    case Expense = 'EXPENSE';
    case StockAdjustment = 'STOCK_ADJUSTMENT';
    case CashTransfer = 'CASH_TRANSFER';

    case OwnerCapital = 'OWNER_CAPITAL';
    case OwnerDrawing = 'OWNER_DRAWING';
    case AssetPurchase = 'ASSET_PURCHASE';

    case Adjustment = 'ADJUSTMENT';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::PurchaseCredit => 'Credit purchase',
            self::PurchaseCash => 'Cash purchase',
            self::PurchaseReturn => 'Purchase return',
            self::SaleCash => 'Cash sale',
            self::SaleCredit => 'Credit sale',
            self::SalesReturn => 'Sales return',
            self::CostOfGoodsSold => 'Cost of goods sold',
            self::Collection => 'Market collection',
            self::DiscountAllowed => 'Discount allowed',
            self::BadDebt => 'Bad debt write-off',
            self::CompanyPayment => 'Company payment',
            self::DiscountReceived => 'Discount received',
            self::Expense => 'Expense',
            self::StockAdjustment => 'Stock adjustment',
            self::CashTransfer => 'Cash transfer',
            self::OwnerCapital => 'Owner capital introduced',
            self::OwnerDrawing => 'Owner drawing',
            self::AssetPurchase => 'Fixed asset purchase',
            self::Adjustment => 'Adjustment',
            self::Reversal => 'Reversal',
        };
    }

    /** Corrections must say why. Enforced by the poster, not by convention. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Adjustment, self::Reversal], true);
    }

    /** Only the opening entry may touch Opening Balance Equity. */
    public function isOpening(): bool
    {
        return $this === self::Opening;
    }
}
