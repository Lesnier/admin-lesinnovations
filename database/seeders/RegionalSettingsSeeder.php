<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RegionalSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['country_code' => 'ES', 'country_name' => 'Spain',    'multiplier' => 1.3,  'hourly_rate' => 25],
            ['country_code' => 'IE', 'country_name' => 'Ireland',  'multiplier' => 1.4,  'hourly_rate' => 45],
            ['country_code' => 'CA', 'country_name' => 'Canada',   'multiplier' => 1.4,  'hourly_rate' => 45],
            ['country_code' => 'US', 'country_name' => 'USA',      'multiplier' => 1.5,  'hourly_rate' => 45],
            ['country_code' => 'EC', 'country_name' => 'Ecuador',  'multiplier' => 0.65, 'hourly_rate' => 16],
            ['country_code' => 'MX', 'country_name' => 'Mexico',   'multiplier' => 0.5,  'hourly_rate' => 14],
            ['country_code' => 'CO', 'country_name' => 'Colombia', 'multiplier' => 0.30, 'hourly_rate' => 12],
            ['country_code' => 'DEFAULT', 'country_name' => 'Global', 'multiplier' => 1.1, 'hourly_rate' => 50],
        ];

        foreach ($settings as $setting) {
            \App\Models\RegionalSetting::updateOrCreate(
                ['country_code' => $setting['country_code']],
                $setting
            );
        }
    }
}
