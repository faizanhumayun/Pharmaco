<?php

namespace App\Domain\Expenses\Actions;

use App\Domain\Daily\DayWriter;
use App\Models\Business;
use App\Models\DailyEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Adds one expense to a day, from outside the daily form.
 *
 * An expense belongs to a day's entry — that is where it posts from and where
 * the day's figures count it — so this does exactly what the daily form would:
 * it takes the day as it stands, adds the line, and saves it the same way.
 */
class AddExpenseToDay
{
    public function __construct(private readonly DayWriter $day) {}

    public function handle(
        Business $business,
        Carbon $date,
        string $head,
        ?string $description,
        Money $amount,
        User $by,
    ): DailyEntry {
        return $this->day->write($business, $date, function (array $data) use ($head, $description, $amount) {
            $data['expenses'][] = [
                'category' => trim($head),
                'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                'amount' => $amount->toDecimal(),
            ];

            return $data;
        }, $by);
    }
}
