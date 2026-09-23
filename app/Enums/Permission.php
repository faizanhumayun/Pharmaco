<?php

namespace App\Enums;

/**
 * The permission registry, named as verbs on resources.
 *
 * Roles are bundles of these, so adding a fourth role later is configuration
 * rather than code — and no controller ever compares a role string.
 * Later phases add their own cases; the roles below expand automatically.
 */
enum Permission: string
{
    // Business administration
    case BusinessView = 'business.view';
    case BusinessConfigure = 'business.configure';
    case BusinessManageUsers = 'business.manage_users';

    // Opening balance (Phase 4)
    case OpeningBalanceView = 'opening_balance.view';
    case OpeningBalanceCreate = 'opening_balance.create';
    case OpeningBalanceFinalize = 'opening_balance.finalize';

    // Daily entry (Phase 5)
    case DailyEntryView = 'daily_entry.view';
    case DailyEntryCreate = 'daily_entry.create';
    case DailyEntryPost = 'daily_entry.post';

    // Closing (Phase 6)
    case ClosingView = 'closing.view';
    case ClosingFinalize = 'closing.finalize';
    case ClosingReopen = 'closing.reopen';

    // Ledger corrections (Phase 3+)
    case TransactionView = 'transaction.view';
    case TransactionAdjust = 'transaction.adjust';
    case TransactionReverse = 'transaction.reverse';

    // Stock verification (Phase 8)
    case StockVerify = 'stock.verify';

    // Money movements restricted to the owner
    case OwnerEquityRecord = 'owner_equity.record';
    case BadDebtWriteOff = 'bad_debt.write_off';

    case ExpenseCreate = 'expense.create';

    // Company catalogue and price-list imports
    case ProductView = 'product.view';
    case ProductImport = 'product.import';

    // Order forms sent to companies
    case OrderView = 'order.view';
    case OrderCreate = 'order.create';
    case OrderSend = 'order.send';

    // Selling at the counter (Phase 11)
    case PosSell = 'pos.sell';

    // Reporting
    case ReportView = 'report.view';
    case ReportViewPosition = 'report.view_position';
    case ReportExport = 'report.export';
    case AuditView = 'audit.view';

    /** @return array<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * Permissions granted to each business role.
     *
     * The operator list is deliberately short: they enter the day's activity and
     * nothing else. They cannot close a day, cannot write off a balance, and
     * cannot see the business's overall position.
     *
     * @return array<string, array<string>>
     */
    public static function forRoles(): array
    {
        $operator = [
            self::BusinessView,
            self::DailyEntryView, self::DailyEntryCreate, self::DailyEntryPost,
            self::ClosingView,
            self::TransactionView,
            self::ExpenseCreate,
            self::ProductView,
            self::OrderView, self::OrderCreate, self::OrderSend,
            self::PosSell,
            self::ReportView,
        ];

        $owner = [
            ...$operator,
            self::BusinessConfigure, self::BusinessManageUsers,
            self::OpeningBalanceView,
            self::ClosingFinalize, self::ClosingReopen,
            self::TransactionAdjust, self::TransactionReverse,
            self::StockVerify,
            self::ProductImport,
            self::OwnerEquityRecord, self::BadDebtWriteOff,
            self::ReportViewPosition, self::ReportExport,
            self::AuditView,
        ];

        /*
         * The owner's manager: everything the owner does except the decisions
         * that stay with the owner — who works here, reopening a closed day,
         * and money moving between the owner and the business.
         */
        $admin = array_values(array_filter($owner, fn (self $p) => ! in_array($p, [
            self::BusinessManageUsers,
            self::ClosingReopen,
            self::OwnerEquityRecord,
            self::BadDebtWriteOff,
        ], true)));

        /*
         * Field staff. The catalogue is what they quote from; nothing
         * financial. Pharmacy order booking adds its own permissions here when
         * it is built.
         */
        $orderBooker = [
            self::BusinessView,
            self::ProductView,
        ];

        $values = fn (array $permissions) => array_map(fn (self $p) => $p->value, $permissions);

        return [
            BusinessRole::Owner->value => $values($owner),
            BusinessRole::Admin->value => $values($admin),
            BusinessRole::Operator->value => $values($operator),
            BusinessRole::OrderBooker->value => $values($orderBooker),
        ];
    }
}
