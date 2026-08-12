<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Order matters: offices first, since every profile points at one, then the
     * fixed demo logins, then the randomised population on top.
     */
    public function run(): void
    {
        $this->call([
            FieldOfficeSeeder::class,
            DemoSeeder::class,
            SampleUsersSeeder::class,
        ]);
    }
}
