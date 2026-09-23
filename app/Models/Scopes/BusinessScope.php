<?php

namespace App\Models\Scopes;

use App\Support\CurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a business-scoped model to the current business.
 *
 * This is the first of three isolation layers; the others are scoped route
 * bindings and policies. It is defence in depth on purpose — a tenancy leak is
 * silent, and one layer failing should not be enough to cause one.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = app(CurrentBusiness::class)->id();

        if ($businessId !== null) {
            $builder->where($model->getTable() . '.business_id', $businessId);
        }
    }
}
