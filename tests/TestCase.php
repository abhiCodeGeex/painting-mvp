<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles must exist for registration and access checks, so seed them whenever
     * the database is refreshed.
     */
    protected bool $seed = true;

    protected string $seeder = RoleSeeder::class;
}
