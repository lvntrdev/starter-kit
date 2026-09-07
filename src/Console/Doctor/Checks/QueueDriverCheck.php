<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Queue driver konfigürasyonunu kontrol eder.
 * sync driver: sadece uyarı (production'da uygun değil).
 * database/redis driver: bağlantı ping'i dener.
 */
class QueueDriverCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.queue_driver.name');
    }

    public function run(): DoctorReport
    {
        $driver = config('queue.default', 'sync');

        if ($driver === 'sync') {
            $env = config('app.env', app()->environment());

            if ($env === 'production') {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.queue_driver.sync_production'),
                    (string) __('sk-doctor.queue_driver.sync_production_hint')
                );
            }

            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.queue_driver.sync_non_production', ['driver' => $driver]),
                (string) __('sk-doctor.queue_driver.sync_non_production_hint')
            );
        }

        if ($driver === 'database') {
            try {
                // jobs tablosunun varlığını kontrol et
                DB::connection()->getSchemaBuilder()->hasTable('jobs');

                return DoctorReport::ok(
                    $this->name(),
                    (string) __('sk-doctor.queue_driver.database_active', ['driver' => $driver])
                );
            } catch (Throwable $e) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.queue_driver.database_error', ['driver' => $driver, 'error' => $e->getMessage()]),
                    (string) __('sk-doctor.queue_driver.database_error_hint')
                );
            }
        }

        if ($driver === 'redis') {
            try {
                Queue::connection('redis')->size();

                return DoctorReport::ok(
                    $this->name(),
                    (string) __('sk-doctor.queue_driver.redis_active', ['driver' => $driver])
                );
            } catch (Throwable $e) {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.queue_driver.redis_error', ['driver' => $driver, 'error' => $e->getMessage()]),
                    (string) __('sk-doctor.queue_driver.redis_error_hint')
                );
            }
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.queue_driver.configured', ['driver' => $driver])
        );
    }
}
