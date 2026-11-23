<?php

namespace App\Support;

use App\Domain\Mirror\MergedImporter;
use App\Models\ShopMirror;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives a record made in a shop's copy an id from that shop's own block.
 *
 * While the back office has a shop's books ({@see ShopMirror::OFFICE}), what
 * is entered here has to go back to the shop's computer afterwards. Every id
 * the shop knows is its block's number plus the shop's own id, so a record
 * made here is numbered the same way — the next free id in the block — and
 * going home is the same subtraction for every record, old or new.
 *
 * Without this a new record would take one of the platform's own ids, which
 * mean nothing on the shop's computer.
 */
class BlockIds
{
    /** @var array<int, bool> shop id → whether its books are entered here */
    private static array $office = [];

    public static function assign(Model $model): void
    {
        // The shop's own computer numbers its records in the ordinary way.
        if (config('mirror.shop') || ! $model->getIncrementing() || $model->getKey() !== null) {
            return;
        }

        $business = $model->getAttribute('business_id') ?? app(CurrentBusiness::class)->id();

        if ($business === null || $business < MergedImporter::BLOCK || $business >= MergedImporter::PLATFORM_FLOOR) {
            return;
        }

        $shop = intdiv((int) $business, MergedImporter::BLOCK);

        self::$office[$shop] ??= (bool) ShopMirror::query()->whereKey($shop)->where('entry', ShopMirror::OFFICE)->exists();

        if (! self::$office[$shop]) {
            return;
        }

        $base = $shop * MergedImporter::BLOCK;

        // Locked, so two records made at once cannot be given the same id.
        $highest = (int) DB::connection($model->getConnectionName())->table($model->getTable())
            ->where($model->getKeyName(), '>=', $base)
            ->where($model->getKeyName(), '<', $base + MergedImporter::BLOCK)
            ->lockForUpdate()
            ->max($model->getKeyName());

        $next = max($highest, $base) + 1;

        if ($next >= $base + MergedImporter::BLOCK) {
            throw new RuntimeException('This shop\'s block of ids is full in '.$model->getTable().'.');
        }

        $model->setAttribute($model->getKeyName(), $next);
    }

    /** Forgets what was looked up: for a long-running process and for tests. */
    public static function forget(): void
    {
        self::$office = [];
    }
}
