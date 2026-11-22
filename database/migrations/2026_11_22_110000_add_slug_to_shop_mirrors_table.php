<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A shop's copy can be opened under the platform's own address —
 * platform.example/copy/shopname — so it no longer needs an address (and a
 * certificate) of its own. The short name in that link is the slug; an
 * address of its own becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_mirrors', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique()->after('name');
            $table->string('host', 190)->nullable()->change();
        });

        foreach (DB::table('shop_mirrors')->whereNull('slug')->get() as $mirror) {
            DB::table('shop_mirrors')->where('id', $mirror->id)->update([
                'slug' => Str::slug($mirror->name).'-'.$mirror->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shop_mirrors', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
