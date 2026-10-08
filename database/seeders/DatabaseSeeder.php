<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Do not seed demo accounts or sample content in production. Create an admin with `php artisan admin:create` and add curriculum through the dashboard.');
        }

        if (app()->environment('testing')) {
            User::firstOrCreate(
                ['email' => 'admin@example.com'],
                ['name' => 'Administrator', 'password' => 'password']
            );
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password']
            );
        }

        $this->call(SampleContentSeeder::class);
    }
}
