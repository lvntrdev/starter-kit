<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Production ortamında config cache'in mevcut olup olmadığını kontrol eder.
 * Local/testing ortamında config cache önerilmez.
 */
class ConfigCacheCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.config_cache.name');
    }

    public function run(): DoctorReport
    {
        $env = config('app.env', app()->environment());
        $cachePath = function_exists('base_path')
            ? base_path('bootstrap/cache/config.php')
            : 'bootstrap/cache/config.php';

        $cacheExists = file_exists($cachePath);

        if ($env === 'production') {
            if (! $cacheExists) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.config_cache.production_missing'),
                    (string) __('sk-doctor.config_cache.production_missing_hint')
                );
            }

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.config_cache.production_ready')
            );
        }

        // Local/testing: cache varsa uyarı (stale config riski)
        if ($cacheExists) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.config_cache.stale_local', ['env' => $env]),
                (string) __('sk-doctor.config_cache.stale_local_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.config_cache.not_required', ['env' => $env])
        );
    }
}
