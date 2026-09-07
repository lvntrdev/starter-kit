<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Varsayılan veritabanı bağlantısını test eder (PDO ping).
 */
class DatabaseConnectionCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.database_connection.name');
    }

    public function run(): DoctorReport
    {
        try {
            DB::connection()->getPdo();

            $driver = DB::connection()->getDriverName();
            $database = DB::connection()->getDatabaseName();

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.database_connection.connected', ['driver' => $driver, 'database' => $database])
            );
        } catch (Throwable $e) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.database_connection.connection_failed', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.database_connection.connection_failed_hint')
            );
        }
    }
}
