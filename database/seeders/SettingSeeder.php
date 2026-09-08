<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // Ours to define, so re-seeding may reset them.
        $managed = [
            ['key' => 'app_name', 'value' => 'Kawakib'],
            ['key' => 'app_version', 'value' => '1.0.0'],
        ];

        foreach ($managed as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }

        /*
         * Theirs to fill in, so re-seeding must NOT touch them.
         *
         * firstOrCreate, not updateOrCreate: these exist so the row is there to
         * edit in the staff console, and a deploy that re-runs the seeders
         * would otherwise wipe the support address somebody set last week.
         *
         * example.com is reserved by RFC 2606 and can never receive mail, so a
         * forgotten placeholder BOUNCES rather than quietly swallowing the
         * message of a customer who has just been suspended. Change it in the
         * console; a real-looking address here would be the dangerous one.
         */
        $configurable = [
            SiteSettings::SUPPORT_URL => 'support@example.com',
        ];

        foreach ($configurable as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
