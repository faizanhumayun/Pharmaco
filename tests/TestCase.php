<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles and permissions are baseline application data, not fixtures — the
     * app cannot assign a role that has never been seeded, in tests or in
     * production. Seeding them here keeps the two environments honest.
     */
    protected bool $seed = true;

    protected string $seeder = RolePermissionSeeder::class;
}
