<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

class TimezoneStorageCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.timezone_storage.name');
    }

    public function run(): DoctorReport
    {
        $timezone = config('app.timezone');

        if ($timezone !== 'UTC') {
            $configured = is_string($timezone) ? $timezone : 'unset';

            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.timezone_storage.non_utc', ['timezone' => $configured]),
                (string) __('sk-doctor.timezone_storage.non_utc_hint')
            );
        }

        try {
            $connection = DB::connection();
            $driver = strtolower($connection->getDriverName());

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return DoctorReport::ok(
                    $this->name(),
                    (string) __('sk-doctor.timezone_storage.driver_not_applicable', ['driver' => $driver])
                );
            }

            $result = $connection->selectOne('SELECT @@session.time_zone AS time_zone');
            $sessionTimezone = is_object($result) && isset($result->time_zone)
                ? (string) $result->time_zone
                : '';

            if ($sessionTimezone === '') {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.timezone_storage.session_unverifiable'),
                    (string) __('sk-doctor.timezone_storage.session_unverifiable_hint')
                );
            }
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.timezone_storage.session_error', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.timezone_storage.session_error_hint')
            );
        }

        if (! in_array($sessionTimezone, ['+00:00', 'UTC'], true)) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.timezone_storage.session_mismatch', [
                    'driver' => $driver,
                    'session_timezone' => $sessionTimezone,
                ]),
                (string) __('sk-doctor.timezone_storage.session_mismatch_hint', ['driver' => $driver])
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.timezone_storage.healthy', ['driver' => $driver, 'session_timezone' => $sessionTimezone])
        );
    }
}
