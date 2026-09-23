<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Business> */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = fake()->unique()->company() . ' Pharma Distribution';

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'business_type' => BusinessType::Distributor,
            'status' => BusinessStatus::Setup,
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'created_by' => User::factory(),
        ];
    }

    /** A business whose opening balance is finalized and which accepts activity. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => BusinessStatus::Active,
            'opening_date' => now()->subMonth()->toDateString(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => BusinessStatus::Suspended]);
    }
}
