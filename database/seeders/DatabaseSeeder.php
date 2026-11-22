<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const DEFAULT_PASSWORD_HASH = '$2y$12$CJElA6QuuGxroM.4KDgsY.93RNlxbfmzzcU7PCCBAdkeHG.wPwDZG';

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $email = env('PLATFORM_ADMIN_EMAIL', 'faizanhumayun486@gmail.com');

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('PLATFORM_ADMIN_NAME', 'App Owner'),
                // Replaced just below when none was given.
                'password' => env('PLATFORM_ADMIN_PASSWORD') ?: \Illuminate\Support\Str::random(40),
                'is_platform_admin' => true,
                'is_active' => true,
            ]
        );

        /*
         * The built-in password is kept as its hash, never as the word itself:
         * this file is shipped inside every shop's installer, and a password
         * written out in it could be read by anyone who opened the folder.
         * Written straight to the row, because the model would otherwise
         * hash it a second time or refuse it for its settings.
         */
        if ($admin->wasRecentlyCreated && ! env('PLATFORM_ADMIN_PASSWORD')) {
            \Illuminate\Support\Facades\DB::table('users')->where('id', $admin->id)->update(['password' => self::DEFAULT_PASSWORD_HASH]);
        }

        $this->command?->info("Platform admin ready: {$admin->email}");
    }
}
