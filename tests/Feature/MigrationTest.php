<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rolling back and re-running the recent migrations works with a
     * Google-only user present. SQLite cannot reproduce the MySQL failures
     * (error 1553 on the foreign key's index, error 1138 on the NOT NULL
     * password); those orderings were checked by the review against MySQL.
     */
    public function test_recent_migrations_roll_back_and_forward(): void
    {
        User::factory()->google()->create();

        $this->artisan('migrate:rollback', ['--step' => 5])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
    }
}
