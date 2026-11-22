<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which computer something was entered on.
 *
 * A shop has more than one screen — the counter, the back room where stock is
 * received, the owner's laptop — and "who entered this" is only half the
 * answer when two people share a login or one person moves between screens.
 * Each browser that signs in is a device, named by the people who use it
 * ("Laptop POS 1", "Laptop Supply"), and what is entered carries which one.
 *
 * A device belongs to the installation, not to a business: the same laptop
 * may work in two businesses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            // Kept in a long-lived cookie in that browser.
            $table->uuid('uuid')->unique();
            // Given by the people who use it. Empty until they name it.
            $table->string('name', 60)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // The documents money and stock move on.
        foreach (['pos_bills', 'transactions', 'stock_movements', 'orders', 'daily_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            });
        }

        // And every step in the trail: the device, its name as it was then
        // (a device can be renamed later), and which sign-in it was.
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('device_name', 60)->nullable();
            // A short fingerprint of the session, not the session's own id:
            // enough to tell two sign-ins apart, useless for taking one over.
            $table->string('session_key', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn(['device_name', 'session_key']);
        });

        foreach (['pos_bills', 'transactions', 'stock_movements', 'orders', 'daily_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('device_id'));
        }

        Schema::dropIfExists('devices');
    }
};
