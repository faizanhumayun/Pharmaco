<?php

namespace App\Models;

use App\Enums\BusinessRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessUser extends Pivot
{
    protected $table = 'business_user';

    public $incrementing = true;

    protected $fillable = ['business_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
            'is_active' => 'boolean',
        ];
    }
}
