<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's code is made by the system ({@see Company::nextCode()}), and no
 * two companies of a business share one.
 *
 * Companies that have none, or share one with an earlier company of the same
 * business, are given the next code in turn, oldest first. A code that was
 * typed and is unique is kept. Then the database itself refuses a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        $next = [];

        foreach (DB::table('companies')->orderBy('id')->get(['id', 'business_id', 'code']) as $row) {
            $code = trim((string) $row->code);
            $key = mb_strtolower($code);

            if ($code !== '' && ! isset($seen[$row->business_id][$key])) {
                $seen[$row->business_id][$key] = true;

                continue;
            }

            do {
                $next[$row->business_id] = ($next[$row->business_id] ?? 0) + 1;
                $code = Company::formatCode($next[$row->business_id]);
            } while (isset($seen[$row->business_id][mb_strtolower($code)]));

            $seen[$row->business_id][mb_strtolower($code)] = true;
            DB::table('companies')->where('id', $row->id)->update(['code' => $code]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->unique(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
        });
    }
};
