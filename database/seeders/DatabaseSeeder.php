<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed canonical reference data required by tests.
        $this->call([\Database\Seeders\RolesSeeder::class]);

        // No test user factory here; only canonical reference data is seeded.
    }
}
