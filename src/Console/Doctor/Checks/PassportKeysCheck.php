<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Passport OAuth anahtar dosyalarını kontrol eder.
 * storage/oauth-private.key ve storage/oauth-public.key mevcut ve okunabilir olmalı.
 */
class PassportKeysCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.passport_keys.name');
    }

    public function run(): DoctorReport
    {
        if (! class_exists('Laravel\Passport\Passport')) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.passport_keys.not_installed'),
                (string) __('sk-doctor.passport_keys.not_installed_hint')
            );
        }

        $storagePath = function_exists('storage_path') ? storage_path() : '';

        $privateKey = $storagePath.'/oauth-private.key';
        $publicKey = $storagePath.'/oauth-public.key';

        $missing = [];
        $unreadable = [];

        foreach ([$privateKey, $publicKey] as $keyPath) {
            $basename = basename($keyPath);

            if (! file_exists($keyPath)) {
                $missing[] = $basename;

                continue;
            }

            if (! is_readable($keyPath)) {
                $unreadable[] = $basename;
            }
        }

        if ($missing !== []) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.passport_keys.missing', ['files' => implode(', ', $missing)]),
                (string) __('sk-doctor.passport_keys.missing_hint')
            );
        }

        if ($unreadable !== []) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.passport_keys.unreadable', ['files' => implode(', ', $unreadable)]),
                (string) __('sk-doctor.passport_keys.unreadable_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.passport_keys.readable')
        );
    }
}
