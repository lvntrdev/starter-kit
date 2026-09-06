<?php

namespace App\Providers;

use App\Listeners\EnforceMailSendLimit;
use App\Models\Setting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services — schedule the early auth/security config bridge.
     *
     * Laravel Fortify's package provider registers its routes during ITS
     * boot(), which runs BEFORE any app provider's boot() (discovered
     * package providers boot first). The login route's `throttle:login`
     * middleware is attached at that moment based on
     * config('fortify.limiters.login'), and the /register,
     * /forgot-password and /reset-password routes are registered at that
     * same moment behind Features::enabled(config('fortify.features')) —
     * so BOTH gates must be applied in a booting callback: Laravel fires
     * those after ALL register() calls but before ANY provider boot(),
     * i.e. early enough for Fortify's route registration to observe the
     * gated values.
     */
    public function register(): void
    {
        $this->app->booting(function (): void {
            $this->bridgeAuthSecurityConfig();
        });
    }

    /**
     * Push DB-stored auth security settings into runtime config.
     *
     * Fallbacks preserve pre-feature behavior for installs whose DB does
     * not contain the new keys yet: throttle stays ON, no password expiry,
     * and the password policy equals the kit's hardened baseline — the old
     * stub AppServiceProvider raised Password::defaults() to min 10 +
     * mixed case + numbers + symbols, so these fallbacks must match it
     * exactly (anything weaker would silently downgrade existing installs
     * after sk:update). Keep in lockstep with SettingsDefaultsQuery::auth().
     */
    private function bridgeAuthSecurityConfig(): void
    {
        // This runs in a booting() callback — BEFORE any provider boot(), so
        // Eloquent's static connection resolver (set in DatabaseServiceProvider::
        // boot()) is still null. Reading through the Setting model here would
        // fatal with "Call to a member function connection() on null". Use the
        // query builder instead: it resolves the connection from the container's
        // `db` binding, which is already available this early. The whole probe is
        // wrapped so a missing/unconfigured DB (fresh install, pre-migration,
        // sk:update on an unprepared app) degrades to the safe fallbacks below.
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            // Auth-policy keys are never stored encrypted, so the raw `value`
            // column equals what the Setting model would decrypt to.
            $auth = DB::table('settings')
                ->where('group', 'auth')
                ->pluck('value', 'key')
                ->all();
        } catch (\Throwable) {
            return;
        }

        // Login-throttle gate — default ON ('1'). Disabling is a conscious,
        // explicit admin action (security downgrade). Both throttle layers
        // read this key: the route-level `throttle:login` middleware (bound
        // at Fortify route registration) and the EnsureLoginIsNotThrottled
        // step in FortifyServiceProvider's authenticateThrough pipeline.
        //
        // "Disabled" does NOT mean unlimited. Nulling the limiter would strip
        // the route middleware AND drop EnsureLoginIsNotThrottled, leaving web
        // login wide open to brute-force — no admin setting may do that
        // (auth red line). Instead we swap the strict 'login' limiter (10/5/3
        // per minute) for the deliberately generous 'login-relaxed' floor
        // defined in FortifyServiceProvider: it honors the admin's intent to
        // loosen the limit while still capping automated attempts. The limiter
        // key stays non-null so Fortify keeps both throttle layers wired.
        //
        // Note the asymmetry: this key only governs the WEB (Fortify) login.
        // The swap below rewrites the config value Fortify reads when it
        // registers its own routes, and nothing else. The API login route
        // names its dedicated `api-login` limiter literally and the remaining
        // public auth routes carry a plain `throttle:5,1`
        // (stubs/routes/api/public-api.php), so the API keeps its full
        // per-IP + per-account limits regardless of this toggle.
        if (($auth['login_throttle'] ?? '1') === '0') {
            config(['fortify.limiters.login' => 'login-relaxed']);
        }

        // Password policy bridge — read by the PasswordValidationRules
        // trait at request time. min_length is clamped to >= 1 as a safety
        // floor against hand-edited DB rows (the FormRequest enforces 6-128
        // on the normal write path); Password::min(0) must never happen.
        config([
            'starter-kit.password_policy.min_length' => max(1, (int) ($auth['password_min_length'] ?? 10)),
            'starter-kit.password_policy.expiry_days' => max(0, (int) ($auth['password_expiry_days'] ?? 0)),
            'starter-kit.password_policy.require_mixed_case' => ($auth['password_require_mixed_case'] ?? '1') === '1',
            'starter-kit.password_policy.require_numbers' => ($auth['password_require_numbers'] ?? '1') === '1',
            'starter-kit.password_policy.require_symbols' => ($auth['password_require_symbols'] ?? '1') === '1',
        ]);

        $this->gateFortifyFeatures($auth);
    }

    /**
     * Rebuild config('fortify.features') from the auth settings group.
     *
     * This MUST run from the booting() bridge, never from boot(): Fortify's
     * own boot() registers /register, POST /forgot-password and
     * POST /reset-password behind Features::enabled(...), so a features array
     * rebuilt in an app provider's boot() arrives AFTER the routes are already
     * bound — "registration disabled" then leaves the endpoint wide open. The
     * caller has already degraded to the safe fallbacks when the DB is
     * unavailable (fresh install, pre-migration): in that case this method is
     * never reached and config/fortify.php's own feature list stands, exactly
     * as it did before the gate moved.
     *
     * Only the six features the auth settings screen actually governs are
     * recomputed. Any other entry already present in config('fortify.features')
     * — Features::passkeys() ships enabled in Fortify's default config, and a
     * consumer may add its own — is carried over untouched: this gate closes
     * what the admin turned off, it does not silently strip a feature it has
     * no toggle for.
     *
     * @param  array<string, mixed>  $auth
     */
    private function gateFortifyFeatures(array $auth): void
    {
        $managed = [
            Features::registration(),
            Features::resetPasswords(),
            Features::emailVerification(),
            Features::updateProfileInformation(),
            Features::updatePasswords(),
            Features::twoFactorAuthentication(),
        ];

        $features = array_values(array_filter(
            (array) config('fortify.features', []),
            static fn (mixed $feature): bool => ! in_array($feature, $managed, true),
        ));

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
            // The options array is applied as a SIDE EFFECT: Features::
            // twoFactorAuthentication() writes config('fortify-options.
            // two-factor-authentication'), which Fortify reads at route
            // registration to decide whether the 2FA management endpoints
            // carry `password.confirm`. Setting it from here (instead of
            // boot()) is what finally makes that middleware take effect —
            // the Profile > Two Factor tab already performs the
            // /user/confirm-password round-trip the middleware expects.
            $features[] = Features::twoFactorAuthentication([
                'confirm' => true,
                'confirmPassword' => true,
            ]);
        }

        config(['fortify.features' => $features]);
    }

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

                    // Prefer the admin-chosen default language; fall back to the
                    // first active language when it is unset or no longer active.
                    $default = $general['default_language'] ?? null;
                    if (! $default || ! array_key_exists($default, $activeLanguages)) {
                        $default = array_key_first($activeLanguages);
                    }

                    config(['app.locale' => $default]);
                    config(['app.default_locale' => $default]);
                    app()->setLocale($default);
                }
            }
            if ($general['currency'] ?? null) {
                config(['app.currency' => $general['currency']]);
            }
            if ($general['date_format'] ?? null) {
                config(['app.date_format' => $general['date_format']]);
            }
        }

        // Auth — the Fortify feature gate is NOT applied here. It runs in the
        // booting() bridge above (gateFortifyFeatures), because Fortify binds
        // its /register and password-reset routes during its own boot(), which
        // happens before this method. Rebuilding the array here would flip
        // Features::enabled() without ever removing the route.

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
                // Laravel's SMTP mailer expects null (not the string "none") to send without TLS.
                $encryption = $mail['encryption'] === 'none' ? null : $mail['encryption'];
                config(['mail.mailers.smtp.encryption' => $encryption]);
            }
            if ($mail['from_address'] ?? null) {
                config(['mail.from.address' => $mail['from_address']]);
            }
            if ($mail['from_name'] ?? null) {
                config(['mail.from.name' => $mail['from_name']]);
            }
            // Apply a global Reply-To to every outgoing mailable when configured.
            if (! empty($mail['reply_to'])) {
                config(['mail.reply_to.address' => $mail['reply_to']]);
                Mail::alwaysReplyTo($mail['reply_to'], $mail['from_name'] ?? null);
            }
            // Expose the hourly send cap to the throttle listener (0 = unlimited).
            config(['mail.send_limit' => (int) ($mail['send_limit'] ?? 0)]);
        }

        // Enforce the hourly outgoing-mail cap. The listener short-circuits when
        // the limit is 0 (unlimited), so registering it unconditionally is safe.
        Event::listen(MessageSending::class, EnforceMailSendLimit::class);

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

        // File Manager — push DB-stored upload constraints into the package config
        // so UploadFileRequest reads the correct admin-configured values at runtime.
        if ($fileManager = $settings['file_manager'] ?? null) {
            if (isset($fileManager['max_size_mb'])) {
                config(['file-manager.settings.max_size_mb' => (int) $fileManager['max_size_mb']]);
                // The media library enforces its own ceiling (media-library.max_file_size,
                // 10 MB by default) after validation; keep it in step so an admin-permitted
                // larger upload is not refused once the request rule has passed.
                config(['media-library.max_file_size' => (int) $fileManager['max_size_mb'] * 1024 * 1024]);
            }
            if (isset($fileManager['storage_quota_gb'])) {
                config(['file-manager.settings.storage_quota_gb' => (int) $fileManager['storage_quota_gb']]);
            }
            if (isset($fileManager['accepted_mimes'])) {
                $mimes = $fileManager['accepted_mimes'];
                if (is_string($mimes)) {
                    $decoded = json_decode($mimes, true);
                    $mimes = is_array($decoded) ? $decoded : [];
                }
                config(['file-manager.settings.accepted_mimes' => $mimes]);
            }
            if (isset($fileManager['allow_video'])) {
                config(['file-manager.settings.allow_video' => $fileManager['allow_video'] === '1']);
            }
            if (isset($fileManager['allow_audio'])) {
                config(['file-manager.settings.allow_audio' => $fileManager['allow_audio'] === '1']);
            }
            if (isset($fileManager['enable_trash'])) {
                config(['file-manager.settings.enable_trash' => $fileManager['enable_trash'] === '1']);
            }
            if (isset($fileManager['trash_retention_days'])) {
                config(['file-manager.settings.trash_retention_days' => (int) $fileManager['trash_retention_days']]);
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
