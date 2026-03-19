<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class _03_SettingSeeder extends Seeder
{
    /**
     * Seed default settings from current config/.env values.
     */
    public function run(): void
    {
        $defaults = [
            'general' => [
                'app_name' => 'LVNTR Laravel Starter Kit',
                'app_url' => config('app.url'),
                'timezone' => config('app.display_timezone', 'UTC'),
                'languages' => implode(',', array_keys(config('app.languages', ['en' => 'English']))),
                'debug' => config('app.debug') ? '1' : '0',
            ],
            'auth' => [
                'registration' => '1',
                'email_verification' => '0',
                'two_factor' => '0',
                'password_reset' => '1',
            ],
            'mail' => [
                'mailer' => 'smtp',
                'host' => config('mail.mailers.smtp.host', '127.0.0.1'),
                'port' => (string) config('mail.mailers.smtp.port', 587),
                'username' => config('mail.mailers.smtp.username'),
                'password' => config('mail.mailers.smtp.password'),
                'encryption' => config('mail.mailers.smtp.encryption', 'tls'),
                'from_address' => config('mail.from.address', 'hello@example.com'),
                'from_name' => config('mail.from.name', config('app.name')),
            ],
            'storage' => [
                'media_disk' => 'local',
                'spaces_key' => config('filesystems.disks.do.key'),
                'spaces_secret' => config('filesystems.disks.do.secret'),
                'spaces_region' => config('filesystems.disks.do.region'),
                'spaces_bucket' => config('filesystems.disks.do.bucket'),
                'spaces_endpoint' => config('filesystems.disks.do.endpoint'),
                'spaces_url' => config('filesystems.disks.do.url'),
                'aws_key' => config('filesystems.disks.s3.key'),
                'aws_secret' => config('filesystems.disks.s3.secret'),
                'aws_region' => config('filesystems.disks.s3.region'),
                'aws_bucket' => config('filesystems.disks.s3.bucket'),
                'aws_url' => config('filesystems.disks.s3.url'),
                'aws_endpoint' => config('filesystems.disks.s3.endpoint'),
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                Setting::firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value],
                );
            }
        }
    }
}
