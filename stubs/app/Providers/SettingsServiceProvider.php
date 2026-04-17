<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services — override config values from database settings.
     */
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $settings = Setting::allGrouped();

        // General
        if ($general = $settings['general'] ?? null) {
            if ($general['app_name'] ?? null) {
                config(['app.name' => $general['app_name']]);
            }
            if ($general['timezone'] ?? null) {
                config(['app.display_timezone' => $general['timezone']]);
            }
            if ($general['languages'] ?? null) {
                $activeCodes = explode(',', $general['languages']);
                $allLanguages = config('app.available_languages', []);
                $activeLanguages = array_intersect_key($allLanguages, array_flip($activeCodes));

                if ($activeLanguages) {
                    config(['app.languages' => $activeLanguages]);
                    config(['app.locale' => array_key_first($activeLanguages)]);
                    app()->setLocale(array_key_first($activeLanguages));
                }
            }
        }

        // Auth — always rebuild Fortify features array from DB settings
        $auth = $settings['auth'] ?? [];
        $features = [];

        if (($auth['registration'] ?? '1') === '1') {
            $features[] = Features::registration();
        }

        if (($auth['password_reset'] ?? '1') === '1') {
            $features[] = Features::resetPasswords();
        }

        if (($auth['email_verification'] ?? '1') === '1') {
            $features[] = Features::emailVerification();
        }

        $features[] = Features::updateProfileInformation();
        $features[] = Features::updatePasswords();

        if (($auth['two_factor'] ?? '1') === '1') {
            $features[] = Features::twoFactorAuthentication([
                'confirm' => true,
                'confirmPassword' => true,
            ]);
        }

        config(['fortify.features' => $features]);

        // Mail
        if ($mail = $settings['mail'] ?? null) {
            if ($mail['mailer'] ?? null) {
                config(['mail.default' => $mail['mailer']]);
            }
            if ($mail['host'] ?? null) {
                config(['mail.mailers.smtp.host' => $mail['host']]);
            }
            if ($mail['port'] ?? null) {
                config(['mail.mailers.smtp.port' => (int) $mail['port']]);
            }
            if (array_key_exists('username', $mail)) {
                config(['mail.mailers.smtp.username' => $mail['username']]);
            }
            if (array_key_exists('password', $mail)) {
                config(['mail.mailers.smtp.password' => $mail['password']]);
            }
            if (array_key_exists('encryption', $mail)) {
                config(['mail.mailers.smtp.encryption' => $mail['encryption']]);
            }
            if ($mail['from_address'] ?? null) {
                config(['mail.from.address' => $mail['from_address']]);
            }
            if ($mail['from_name'] ?? null) {
                config(['mail.from.name' => $mail['from_name']]);
            }
        }

        // Storage
        if ($storage = $settings['storage'] ?? null) {
            if ($storage['media_disk'] ?? null) {
                config(['media-library.disk_name' => $storage['media_disk']]);
            }

            // DO Spaces
            if ($storage['spaces_key'] ?? null) {
                config(['filesystems.disks.do.key' => $storage['spaces_key']]);
            }
            if ($storage['spaces_secret'] ?? null) {
                config(['filesystems.disks.do.secret' => $storage['spaces_secret']]);
            }
            if ($storage['spaces_region'] ?? null) {
                config(['filesystems.disks.do.region' => $storage['spaces_region']]);
            }
            if ($storage['spaces_bucket'] ?? null) {
                config(['filesystems.disks.do.bucket' => $storage['spaces_bucket']]);
            }
            if ($storage['spaces_endpoint'] ?? null) {
                config(['filesystems.disks.do.endpoint' => $storage['spaces_endpoint']]);
            }
            if ($storage['spaces_url'] ?? null) {
                config(['filesystems.disks.do.url' => $storage['spaces_url']]);
            }

            // AWS S3
            if ($storage['aws_key'] ?? null) {
                config(['filesystems.disks.s3.key' => $storage['aws_key']]);
            }
            if ($storage['aws_secret'] ?? null) {
                config(['filesystems.disks.s3.secret' => $storage['aws_secret']]);
            }
            if ($storage['aws_region'] ?? null) {
                config(['filesystems.disks.s3.region' => $storage['aws_region']]);
            }
            if ($storage['aws_bucket'] ?? null) {
                config(['filesystems.disks.s3.bucket' => $storage['aws_bucket']]);
            }
            if ($storage['aws_url'] ?? null) {
                config(['filesystems.disks.s3.url' => $storage['aws_url']]);
            }
            if ($storage['aws_endpoint'] ?? null) {
                config(['filesystems.disks.s3.endpoint' => $storage['aws_endpoint']]);
            }
        }

        // Turnstile
        if ($turnstile = $settings['turnstile'] ?? null) {
            if (array_key_exists('enabled', $turnstile)) {
                config(['services.turnstile.enabled' => $turnstile['enabled'] === '1']);
            }
            if (array_key_exists('site_key', $turnstile)) {
                config(['services.turnstile.site_key' => $turnstile['site_key']]);
            }
            if (array_key_exists('secret_key', $turnstile)) {
                config(['services.turnstile.secret_key' => $turnstile['secret_key']]);
            }
        }
    }
}
