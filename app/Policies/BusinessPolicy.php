<?php

namespace App\Policies;

use App\Enums\BusinessStatus;
use App\Enums\Permission;
use App\Models\Business;
use App\Models\User;

/**
 * Business-scoped abilities are asked as permissions, never as roles: each
 * role is a bundle in Permission::forRoles(), so a new role is configuration.
 * The App Owner keeps the reach they had before roles multiplied.
 */
class BusinessPolicy
{
    /** Only the platform admin sees the full list of tenants. */
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::BusinessView);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    /** Platform-level configuration: name, type, timezone, status. */
    public function update(User $user, Business $business): bool
    {
        return $user->isPlatformAdmin();
    }

    /**
     * The business's own reference data: companies, pharmacies, expense heads.
     * Kept separate from managing staff so a manager can run the catalogue
     * without deciding who works here.
     */
    public function configure(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::BusinessConfigure);
    }

    /** Who works here and in what role. The owner's decision alone. */
    public function manageMembers(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::BusinessManageUsers);
    }

    /** Trends expose profit and position over time. */
    public function viewReports(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ReportViewPosition);
    }

    /** The ledger exposes the full position, so it follows the same permission. */
    public function viewLedger(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ReportViewPosition);
    }

    /** The business's own trail; the App Owner sees any. */
    public function viewAudit(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::AuditView);
    }

    /** Counting stock posts a real loss or gain. */
    public function verifyStock(User $user, Business $business): bool
    {
        return $business->acceptsTransactions()
            && $this->allows($user, $business, Permission::StockVerify);
    }

    /**
     * The catalogue is reference data, not position: anyone quoting a pack
     * size or a rate needs to read it.
     */
    public function viewProducts(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ProductView);
    }

    /**
     * Importing a price list rewrites what the business believes it is paying,
     * and only while the business is trading.
     */
    public function importProducts(User $user, Business $business): bool
    {
        return $business->acceptsTransactions()
            && $this->allows($user, $business, Permission::ProductImport);
    }

    /**
     * Order forms are operational rather than financial — writing one commits
     * no money and posts nothing — so the people entering the day's activity
     * may raise and send them.
     */
    public function viewOrders(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::OrderView);
    }

    public function manageOrders(User $user, Business $business): bool
    {
        return $business->acceptsTransactions()
            && $this->allows($user, $business, Permission::OrderCreate);
    }

    /** Ringing up a sale: counter work, and only while the business is trading. */
    public function sellAtPos(User $user, Business $business): bool
    {
        return $business->acceptsTransactions()
            && $this->allows($user, $business, Permission::PosSell);
    }

    public function suspend(User $user, Business $business): bool
    {
        return $user->isPlatformAdmin() && $business->status !== BusinessStatus::Archived;
    }

    /**
     * Deleting a business destroys its financial history along with it.
     *
     * Restricted to the App Owner, warned about in the interface, and confirmed
     * by typing the business name — but permitted, because removing a tenant
     * from the platform is a platform decision.
     */
    public function delete(User $user, Business $business): bool
    {
        return $user->isPlatformAdmin();
    }

    public function archive(User $user, Business $business): bool
    {
        return $user->isPlatformAdmin() && $business->opening_date === null;
    }

    private function allows(User $user, Business $business, Permission $permission): bool
    {
        return $user->isPlatformAdmin() || $user->hasBusinessPermission($business, $permission);
    }
}
