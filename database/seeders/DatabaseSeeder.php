<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $email = env('PLATFORM_ADMIN_EMAIL', 'admin@pharmaco.test');

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('PLATFORM_ADMIN_NAME', 'App Owner'),
                'password' => env('PLATFORM_ADMIN_PASSWORD', 'password'),
                'is_platform_admin' => true,
                'is_active' => true,
            ]
        );

        $this->command?->info("Platform admin ready: {$admin->email}");
    }
}
