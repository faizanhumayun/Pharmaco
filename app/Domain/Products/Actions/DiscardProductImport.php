<?php

namespace App\Domain\Products\Actions;

use App\Enums\ImportStatus;
use App\Models\CompanyProductImport;
use RuntimeException;

/**
 * Sets a staged import aside.
 *
 * The import and its rows stay where they are — a list that was rejected is
 * itself worth knowing about, and there are no delete routes here for the same
 * reason there are none anywhere else in this system.
 */
class DiscardProductImport
{
    public function handle(CompanyProductImport $import): CompanyProductImport
    {
        if (! $import->isEditable()) {
            throw new RuntimeException('An import that has been applied cannot be discarded.');
        }

        $import->forceFill(['status' => ImportStatus::Discarded])->save();

        return $import;
    }
}
