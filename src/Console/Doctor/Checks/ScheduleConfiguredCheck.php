<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Schedule'ın konfigüre edilip edilmediğini kontrol eder.
 * Son schedule:run zamanı için last_run_at log kaydına bakılır.
 */
class ScheduleConfiguredCheck implements DoctorCheck
{
    /** schedule:run heartbeat bu süreden (saniye) eskiyse cron durmuş olabilir. */
    private const STALE_THRESHOLD = 300;

    public function name(): string
    {
        return (string) __('sk-doctor.schedule_configured.name');
    }

    public function run(): DoctorReport
    {
        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $events = $schedule->events();
            $count = count($events);

            if ($count === 0) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.schedule_configured.no_tasks'),
                    (string) __('sk-doctor.schedule_configured.no_tasks_hint')
                );
            }

            // Cron canlılığı: schedule:run her dakika bu dosyaya heartbeat yazar
            // (StarterKitServiceProvider CommandFinished listener). Dosya yoksa
            // schedule:run hiç çalışmamış → cron kurulu olmayabilir.
            $lastRunFile = storage_path('framework/.schedule-last-run');

            if (! file_exists($lastRunFile)) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.schedule_configured.never_run', ['count' => $count]),
                    (string) __('sk-doctor.schedule_configured.never_run_hint')
                );
            }

            $timestamp = (int) file_get_contents($lastRunFile);
            $secondsAgo = now()->getTimestamp() - $timestamp;
            $diff = now()->diffForHumans(Carbon::createFromTimestamp($timestamp));

            if ($secondsAgo >= self::STALE_THRESHOLD) {
                return DoctorReport::warn(
                    $this->name(),
                    (string) __('sk-doctor.schedule_configured.stale', ['count' => $count, 'diff' => $diff]),
                    (string) __('sk-doctor.schedule_configured.stale_hint')
                );
            }

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.schedule_configured.healthy', ['count' => $count, 'diff' => $diff])
            );
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.schedule_configured.error', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.schedule_configured.error_hint')
            );
        }
    }
}
