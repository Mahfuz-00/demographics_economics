<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Model events are deliberately left enabled: the public UUIDs on entities and
     * observations are generated in a `creating` hook.
     */
    public function run(): void
    {
        $this->call([
            EntitySeeder::class,
            IndicatorSeeder::class,
            SourceSeeder::class,
            CsvColumnMappingSeeder::class,
            NationalStatisticsOfficeSeeder::class,
        ]);

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => 'Administrator',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            ]
        );
    }
}
