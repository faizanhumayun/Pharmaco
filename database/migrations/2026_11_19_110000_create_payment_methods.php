<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ways of being paid other than cash in the drawer.
 *
 * The list itself belongs to the platform — the back office keeps it — and
 * each business switches on the ones it actually uses. Switching one on gives
 * it an account of its own under Bank, so money that arrived by transfer is
 * never counted as cash in the drawer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            // 'wallet' (JazzCash, Easypaisa) or 'bank'.
            $table->string('kind', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            // The account title or number, as the business wants it printed.
            $table->string('title', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'payment_method_id']);
        });

        foreach (['sale_lines', 'collection_lines'] as $lines) {
            Schema::table($lines, function (Blueprint $table) {
                // Null means cash.
                $table->foreignId('business_payment_method_id')->nullable()
                    ->constrained()->restrictOnDelete();
            });
        }

        Schema::table('pos_bills', function (Blueprint $table) {
            $table->foreignId('pharmacy_id')->nullable()->after('customer_name')
                ->constrained()->restrictOnDelete();
            $table->foreignId('business_payment_method_id')->nullable()->after('received')
                ->constrained()->restrictOnDelete();
        });

        // Bills have carried the customer's name; give each the customer it names.
        DB::statement('
            UPDATE pos_bills
            JOIN pharmacies ON pharmacies.business_id = pos_bills.business_id
                AND pharmacies.name = pos_bills.customer_name
            SET pos_bills.pharmacy_id = pharmacies.id
            WHERE pos_bills.customer_name IS NOT NULL
        ');

        $now = now();
        $order = 0;
        $rows = [];

        foreach (['JazzCash', 'Easypaisa'] as $wallet) {
            $rows[] = ['name' => $wallet, 'kind' => 'wallet', 'is_active' => true, 'sort_order' => $order += 10, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach ([
            'Allied Bank', 'Askari Bank', 'Bank AL Habib', 'Bank Alfalah', 'Bank of Khyber', 'Bank of Punjab',
            'BankIslami', 'Dubai Islamic Bank', 'Faysal Bank', 'Habib Bank (HBL)', 'Habib Metropolitan Bank',
            'JS Bank', 'MCB Bank', 'MCB Islamic Bank', 'Meezan Bank', 'National Bank of Pakistan', 'Samba Bank',
            'Sindh Bank', 'Soneri Bank', 'Standard Chartered', 'Summit Bank', 'United Bank (UBL)', 'Al Baraka Bank',
            'First Women Bank', 'Zarai Taraqiati Bank',
        ] as $bank) {
            $rows[] = ['name' => $bank, 'kind' => 'bank', 'is_active' => true, 'sort_order' => $order += 10, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('payment_methods')->insert($rows);
    }

    public function down(): void
    {
        Schema::table('pos_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_payment_method_id');
            $table->dropConstrainedForeignId('pharmacy_id');
        });

        foreach (['sale_lines', 'collection_lines'] as $lines) {
            Schema::table($lines, fn (Blueprint $table) => $table->dropConstrainedForeignId('business_payment_method_id'));
        }

        Schema::dropIfExists('business_payment_methods');
        Schema::dropIfExists('payment_methods');
    }
};
