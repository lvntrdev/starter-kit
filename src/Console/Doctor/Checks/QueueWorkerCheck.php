<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Kuyruk worker'ının fiilen iş tükettiğine dair hafif sinyaller arar —
 * sentinel job DISPATCH ETMEDEN. `database` driver'da bekleyen en eski
 * job'ın yaşına bakar (backlog-age sezgisi): uzun süredir bekleyen job'lar
 * worker'ın düştüğüne işarettir. `redis`/`sqs` gibi async driver'larda yaş
 * bilgisi ucuz alınamaz — canlılık doğrudan doğrulanamadığı için warn'la
 * worker çalıştırmayı hatırlatır. Tek istisna: `redis` + Horizon kurulu ise
 * master supervisor kaydı okunur ve canlılık gerçekten doğrulanır.
 * `sync` driver worker gerektirmez.
 */
class QueueWorkerCheck implements DoctorCheck
{
    /** Bekleyen job bu süreden (saniye) uzun süredir işlenmediyse worker down şüphesi. */
    private const STALE_THRESHOLD = 300;

    public function name(): string
    {
        return (string) __('sk-doctor.queue_worker.name');
    }

    public function run(): DoctorReport
    {
        $driver = config('queue.default', 'sync');

        if ($driver === 'sync') {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.queue_worker.sync')
            );
        }

        if ($driver === 'database') {
            return $this->checkDatabaseBacklog();
        }

        if ($driver === 'redis' && ($horizon = $this->checkHorizon()) !== null) {
            return $horizon;
        }

        // redis / sqs / beanstalkd: bekleyen job yaşı ucuz sorgulanamıyor.
        return DoctorReport::warn(
            $this->name(),
            (string) __('sk-doctor.queue_worker.async_unverifiable', ['driver' => $driver]),
            (string) __('sk-doctor.queue_worker.async_unverifiable_hint')
        );
    }

    /**
     * Horizon kuruluysa canlılık doğrudan okunabilir: master supervisor kaydı
     * Redis'te tutulur. Horizon yoksa / okunamıyorsa null döner ve genel
     * async uyarısına düşülür.
     */
    private function checkHorizon(): ?DoctorReport
    {
        // Horizon paket bağımlılığı değil; FQCN string olarak tutulur ki
        // kurulu olmadığı projelerde statik analiz de derleme de patlamasın.
        $repository = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

        if (! interface_exists($repository)) {
            return null;
        }

        try {
            /** @var array<int, object> $masters */
            $masters = app($repository)->all();
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.queue_worker.horizon_unreadable', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.queue_worker.horizon_unreadable_hint')
            );
        }

        if ($masters === []) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.queue_worker.horizon_no_master'),
                (string) __('sk-doctor.queue_worker.horizon_no_master_hint')
            );
        }

        $paused = array_filter($masters, fn ($master) => ($master->status ?? null) === 'paused');

        if (count($paused) === count($masters)) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.queue_worker.horizon_paused'),
                (string) __('sk-doctor.queue_worker.horizon_paused_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.queue_worker.horizon_running', ['count' => count($masters)])
        );
    }

    private function checkDatabaseBacklog(): DoctorReport
    {
        try {
            $connection = config('queue.connections.database.connection')
                ?: config('database.default');
            $table = (string) config('queue.connections.database.table', 'jobs');

            $schema = DB::connection($connection)->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.queue_worker.database_table_missing', ['table' => $table]),
                    (string) __('sk-doctor.queue_worker.database_table_missing_hint')
                );
            }

            $now = time();

            $oldest = DB::connection($connection)->table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->orderBy('available_at')
                ->first(['available_at']);

            if ($oldest === null) {
                return DoctorReport::ok(
                    $this->name(),
                    (string) __('sk-doctor.queue_worker.database_empty')
                );
            }

            $waitedFor = $now - (int) $oldest->available_at;

            if ($waitedFor >= self::STALE_THRESHOLD) {
                $pending = DB::connection($connection)->table($table)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $now)
                    ->count();

                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.queue_worker.database_stale', [
                        'count' => $pending,
                        'waited' => $this->humanize($waitedFor),
                    ]),
                    (string) __('sk-doctor.queue_worker.database_stale_hint')
                );
            }

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.queue_worker.database_healthy', ['waited' => $this->humanize($waitedFor)])
            );
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.queue_worker.database_error', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.queue_worker.database_error_hint')
            );
        }
    }

    private function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        return intdiv($minutes, 60).'h';
    }
}
