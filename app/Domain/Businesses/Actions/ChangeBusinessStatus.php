<?php

namespace App\Domain\Businesses\Actions;

use App\Enums\BusinessStatus;
use App\Models\Business;
use RuntimeException;

class ChangeBusinessStatus
{
    public function handle(Business $business, BusinessStatus $status): Business
    {
        if ($status === BusinessStatus::Active && $business->opening_date === null) {
            // Activation is earned by finalizing an opening balance, not granted
            // by an admin — otherwise transactions would have no starting point.
            throw new RuntimeException(
                'This business cannot be activated until its opening balance is finalized.'
            );
        }

        if ($status === BusinessStatus::Archived && $business->opening_date !== null) {
            throw new RuntimeException(
                'A business with financial history cannot be archived. Suspend it instead.'
            );
        }

        $business->status = $status;
        $business->save();

        return $business;
    }
}
