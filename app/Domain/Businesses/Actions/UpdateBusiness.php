<?php

namespace App\Domain\Businesses\Actions;

use App\Models\Business;

class UpdateBusiness
{
    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data): Business
    {
        // The timezone decides which calendar day a transaction belongs to, so
        // it is frozen once the business has financial history.
        if ($business->opening_date !== null) {
            unset($data['timezone'], $data['currency']);
        }

        $business->fill(array_filter(
            $data,
            fn ($key) => in_array($key, [
                'name', 'business_type', 'currency', 'timezone', 'stock_unit',
                'address', 'phone', 'email', 'ntn', 'notes',
            ], true),
            ARRAY_FILTER_USE_KEY
        ));

        $business->save();

        return $business;
    }
}
