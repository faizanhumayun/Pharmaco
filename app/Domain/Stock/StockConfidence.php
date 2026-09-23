<?php

namespace App\Domain\Stock;

use App\Models\Business;
use App\Models\StockVerification;

/**
 * How much the stock figure can be trusted.
 *
 * A number with a known error bar is useful; a number with an unknown one is
 * dangerous. Presenting an estimate and a counted figure with identical
 * confidence is how a dashboard misleads someone doing everything right.
 */
class StockConfidence
{
    public const STALE_AFTER_DAYS = 45;

    public function for(Business $business): array
    {
        $last = StockVerification::forBusiness($business)
            ->orderByDesc('business_date')
            ->first();

        if ($last === null) {
            return [
                'verified' => false,
                'days' => null,
                'drift' => null,
                'stale' => true,
                'label' => 'never verified',
                'detail' => 'Stock has never been counted, so its error is unbounded and growing.',
            ];
        }

        $days = (int) $last->business_date->diffInDays($business->today());

        return [
            'verified' => true,
            'days' => $days,
            'drift' => $last->variance_pct,
            'stale' => $days > self::STALE_AFTER_DAYS,
            'label' => $days === 0 ? 'verified today' : "verified {$days} days ago",
            'detail' => sprintf(
                'Last physical count on %s found a %s%.2f%% difference.',
                $last->business_date->format('d M Y'),
                $last->variance_pct > 0 ? '+' : '',
                $last->variance_pct
            ),
        ];
    }
}
