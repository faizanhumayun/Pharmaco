<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shops that run Pharmaco on their own computer and keep a read-only copy
 * here.
 *
 * Each has a database of its own on the platform — an exact copy of the
 * shop's, replaced whenever the shop sends a snapshot — and an address of its
 * own that serves it. This table is the platform's list of them; it is not
 * part of any business's books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_mirrors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            // The address the copy is opened at: shopname.platform.example
            $table->string('host', 190)->unique();
            // The database that holds the copy, and how to reach it when the
            // platform's own login is not allowed into it.
            $table->string('database', 64);
            $table->string('db_username', 64)->nullable();
            $table->text('db_password')->nullable();
            // Only the hash: the token is shown once, when it is made.
            $table->string('token_hash', 64)->unique();
            // When a snapshot last arrived, and when the shop made it.
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('snapshot_made_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_mirrors');
    }
};
