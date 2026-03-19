<?php

namespace App\Domain\Setting\Queries;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Query: Resolve settings with config fallbacks for each group.
 */
class SettingsDefaultsQuery
{
    /**
     * Get all settings groups with defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'general' => $this->general(),
            'auth' => $this->auth(),
            'mail' => $this->mail(),
            'storage' => $this->storage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function general(): array
    {
        $stored = Setting::getGroup('general');
        $defaultLanguages = implode(',', array_keys(config('app.languages', ['en' => 'English'])));

        $logoPath = $stored['logo'] ?? null;

        return [
            'app_name' => $stored['app_name'] ?? config('app.name'),
            'app_url' => $stored['app_url'] ?? config('app.url'),
            'timezone' => $stored['timezone'] ?? config('app.display_timezone'),
            'languages' => explode(',', $stored['languages'] ?? $defaultLanguages),
            'debug' => ($stored['debug'] ?? (config('app.debug') ? '1' : '0')) === '1',
            'logo_url' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function auth(): array
    {
        $stored = Setting::getGroup('auth');

        return [
            'registration' => ($stored['registration'] ?? '1') === '1',
            'email_verification' => ($stored['email_verification'] ?? '1') === '1',
            'two_factor' => ($stored['two_factor'] ?? '1') === '1',
            'password_reset' => ($stored['password_reset'] ?? '1') === '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mail(): array
    {
        $stored = Setting::getGroup('mail');

        return [
            'mailer' => $stored['mailer'] ?? config('mail.default'),
            'host' => $stored['host'] ?? config('mail.mailers.smtp.host'),
            'port' => (int) ($stored['port'] ?? config('mail.mailers.smtp.port')),
            'username' => $stored['username'] ?? config('mail.mailers.smtp.username'),
            'password' => $stored['password'] ?? config('mail.mailers.smtp.password'),
            'encryption' => $stored['encryption'] ?? config('mail.mailers.smtp.encryption'),
            'from_address' => $stored['from_address'] ?? config('mail.from.address'),
            'from_name' => $stored['from_name'] ?? config('mail.from.name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storage(): array
    {
        $stored = Setting::getGroup('storage');

        return [
            'media_disk' => $stored['media_disk'] ?? config('media-library.disk_name'),
            'spaces_key' => $stored['spaces_key'] ?? config('filesystems.disks.do.key'),
            'spaces_secret' => $stored['spaces_secret'] ?? config('filesystems.disks.do.secret'),
            'spaces_region' => $stored['spaces_region'] ?? config('filesystems.disks.do.region'),
            'spaces_bucket' => $stored['spaces_bucket'] ?? config('filesystems.disks.do.bucket'),
            'spaces_endpoint' => $stored['spaces_endpoint'] ?? config('filesystems.disks.do.endpoint'),
            'spaces_url' => $stored['spaces_url'] ?? config('filesystems.disks.do.url'),
            'aws_key' => $stored['aws_key'] ?? config('filesystems.disks.s3.key'),
            'aws_secret' => $stored['aws_secret'] ?? config('filesystems.disks.s3.secret'),
            'aws_region' => $stored['aws_region'] ?? config('filesystems.disks.s3.region'),
            'aws_bucket' => $stored['aws_bucket'] ?? config('filesystems.disks.s3.bucket'),
            'aws_url' => $stored['aws_url'] ?? config('filesystems.disks.s3.url'),
            'aws_endpoint' => $stored['aws_endpoint'] ?? config('filesystems.disks.s3.endpoint'),
        ];
    }
}
