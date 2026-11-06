<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests run through HTTP against a real (in-memory) SQLite database,
 * seeded with the demo data at a fixed moment so time can be moved precisely.
 */
abstract class DemoDataTestCase extends TestCase
{
    use RefreshDatabase;

    protected CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = CarbonImmutable::parse('2026-08-10T09:00:00Z');
        $this->travelTo($this->start);
        $this->seed();
    }

    protected function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    protected function vendor(string $name): Vendor
    {
        return Vendor::where('name', $name)->firstOrFail();
    }
}
