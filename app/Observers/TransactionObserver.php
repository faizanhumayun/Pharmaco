<?php

namespace App\Observers;

use App\Enums\OpeningBalanceStatus;
use App\Enums\TransactionType;
use App\Models\OpeningBalance;
use App\Models\Transaction;

class TransactionObserver
{
    /**
     * Locks the opening balance the moment the business trades.
     *
     * A finalized opening balance already has no edit path, so this changes
     * nothing mechanically — it changes what the screen says. Once there is
     * activity built on top of the starting position, "finalized" understates
     * how settled it is, and the distinction matters to whoever reads it later.
     */
    public function created(Transaction $transaction): void
    {
        if ($transaction->type === TransactionType::Opening) {
            return;
        }

        OpeningBalance::query()
            ->withoutGlobalScopes()
            ->where('business_id', $transaction->business_id)
            ->where('status', OpeningBalanceStatus::Finalized)
            ->update(['status' => OpeningBalanceStatus::Locked]);
    }
}
