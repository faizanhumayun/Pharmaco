<?php

namespace App\Support\Concerns;

use App\Models\Device;
use App\Support\CurrentDevice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For a record that should say which computer it was entered on.
 *
 * Stamped once, when the record is created, from the device the request came
 * from — never from a form field. Left empty for work no device did (the
 * scheduler, an import).
 */
trait RecordsDevice
{
    public static function bootRecordsDevice(): void
    {
        static::creating(function ($model) {
            $model->device_id ??= app(CurrentDevice::class)->id();
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
