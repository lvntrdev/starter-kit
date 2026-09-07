<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Lvntr\StarterKit\Console\Doctor\Checks\ActivityLogSecretsCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\ConfigCacheCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\DatabaseConnectionCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\DataEncryptionKeyCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\FileManagerDiskCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\LogChannelCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\LogStackCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\MailDriverCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\NodeVersionCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\NpmBuildArtifactsCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\PassportKeysCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\PermissionResourcesDriftCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\PhpExtensionsCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\QueueDriverCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\QueueWorkerCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\RedisConnectionCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\ScheduleConfiguredCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\StorageSymlinkCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\ThemeManifestCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\TimezoneStorageCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\UnresolvedRouteCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\WritableDirectoriesCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\Console\Doctor\DoctorStatus;
use Symfony\Component\Console\Helper\TableStyle;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'sk:doctor
        {--json : Output results as JSON (for admin UI)}
        {--only= : Comma-separated check names to run (e.g. database,redis)}';

    protected $description = 'Check the Starter Kit installation: PHP extensions, DB, Redis, Passport, queue, and more.';

    /**
     * Legacy `--only` selectors kept working after the switch to class-derived,
     * locale-independent ids: these three display names never matched their own
     * class name, and the published docs list them.
     */
    private const SELECTOR_ALIASES = [
        'filemanager-disk' => 'file-manager-disk',
        'permission-matrix' => 'permission-resources-drift',
        'unresolved-routes' => 'unresolved-route',
    ];

    /**
     * sk:doctor exit code şeması (plan gereği):
     *   0  — tüm checkler ok (Command::SUCCESS)
     *   1  — en az bir warn var, fail yok
     *   2  — en az bir fail var
     *
     * Not: Laravel Command::FAILURE = 1 olup plan şemasındaki "fail = 2"
     * ile örtüşmez. Bu nedenle EXIT_FAIL sabiti ayrı tanımlandı.
     */
    private const EXIT_WARN = 1;

    private const EXIT_FAIL = 2;

    /**
     * Check başına sert zaman sınırı (saniye). Tek bir asılı DB/Redis/SMTP
     * kontrolünün doctor'ı süresiz kilitlemesini önler. Bireysel check'ler
     * ~2sn içinde dönmeyi hedefler (DoctorCheck arayüzü); sınır, iki ardışık
     * 2sn socket işlemine pay bırakacak şekilde 5sn'dir.
     */
    private const CHECK_TIMEOUT = 5;

    public function handle(): int
    {
        $checks = $this->buildChecks();
        $reports = $this->runChecks($checks);

        if ($this->option('json')) {
            return $this->outputJson($reports);
        }

        return $this->outputTable($reports);
    }

    /** @return list<DoctorCheck> */
    private function buildChecks(): array
    {
        $all = [
            new PhpExtensionsCheck,
            new NodeVersionCheck,
            new DatabaseConnectionCheck,
            new RedisConnectionCheck,
            new PassportKeysCheck,
            new StorageSymlinkCheck,
            new WritableDirectoriesCheck,
            new LogChannelCheck,
            new LogStackCheck,
            new QueueDriverCheck,
            new QueueWorkerCheck,
            new ScheduleConfiguredCheck,
            new TimezoneStorageCheck,
            new ActivityLogSecretsCheck,
            new DataEncryptionKeyCheck,
            new PermissionResourcesDriftCheck,
            new UnresolvedRouteCheck,
            new MailDriverCheck,
            new NpmBuildArtifactsCheck,
            new ConfigCacheCheck,
            new FileManagerDiskCheck,
            new ThemeManifestCheck,
        ];

        $only = $this->option('only');

        if ($only === null || $only === '') {
            return $all;
        }

        $selected = array_map(
            fn (string $selector) => self::SELECTOR_ALIASES[$selector] ?? $selector,
            array_map(fn (string $s) => strtolower(trim($s)), explode(',', (string) $only))
        );

        return array_filter(
            $all,
            fn (DoctorCheck $check) => in_array($this->selectorFor($check), $selected, true)
        );
    }

    /**
     * The locale-independent `--only` selector of a check.
     *
     * Derived from the CLASS name, never from `name()`: the display name goes
     * through the `sk-doctor.*` translations, so a `tr` locale used to make every
     * documented selector match nothing and return an empty, exit-0 report.
     */
    private function selectorFor(DoctorCheck $check): string
    {
        $class = (new \ReflectionClass($check))->getShortName();

        return strtolower(
            preg_replace('/(?<!^)[A-Z]/', '-$0', preg_replace('/Check$/', '', $class)) ?? $class
        );
    }

    /**
     * @param  list<DoctorCheck>  $checks
     * @return list<DoctorReport>
     */
    private function runChecks(array $checks): array
    {
        $reports = [];

        foreach ($checks as $check) {
            $reports[] = $this->runGuarded($check);
        }

        return $reports;
    }

    /**
     * Tek bir check'i zaman sınırı ve hata koruması altında çalıştırır.
     *
     * pcntl varsa: SIGALRM ile CHECK_TIMEOUT saniyede sert kesme — asılı bir
     * check aborte edilip warn olarak raporlanır (doctor kilitlenmez).
     * pcntl yoksa (graceful degrade): süre ölçülür; check sınırı aşarsa
     * sonucun mesajına süre notu eklenir (en azından aşım raporlanır).
     */
    private function runGuarded(DoctorCheck $check): DoctorReport
    {
        $canInterrupt = function_exists('pcntl_async_signals')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal');

        $start = microtime(true);

        if ($canInterrupt) {
            return $this->runWithHardTimeout($check);
        }

        try {
            $report = $check->run();
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $check->name(),
                'Check failed with an unexpected error: '.$e->getMessage(),
                'This may indicate an environment problem; re-run after checking the service.'
            );
        }

        $elapsed = microtime(true) - $start;

        if ($elapsed > self::CHECK_TIMEOUT) {
            return new DoctorReport(
                $report->name,
                $report->status,
                $report->message.sprintf(' (slow: took %.1fs, over the %ds budget)', $elapsed, self::CHECK_TIMEOUT),
                $report->hint,
            );
        }

        return $report;
    }

    private function runWithHardTimeout(DoctorCheck $check): DoctorReport
    {
        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, static function (): void {
            throw new \RuntimeException('__doctor_check_timeout__');
        });

        pcntl_alarm(self::CHECK_TIMEOUT);

        try {
            return $check->run();
        } catch (Throwable $e) {
            if ($e->getMessage() === '__doctor_check_timeout__') {
                return DoctorReport::warn(
                    $check->name(),
                    sprintf('Check exceeded the %ds timeout and was aborted.', self::CHECK_TIMEOUT),
                    'The underlying service (DB/Redis/SMTP) may be unreachable or hung.'
                );
            }

            return DoctorReport::warn(
                $check->name(),
                'Check failed with an unexpected error: '.$e->getMessage(),
                'This may indicate an environment problem; re-run after checking the service.'
            );
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, is_callable($previousHandler) ? $previousHandler : SIG_DFL);
            pcntl_async_signals($previousAsync);
        }
    }

    /**
     * @param  list<DoctorReport>  $reports
     */
    private function outputTable(array $reports): int
    {
        $this->newLine();
        $this->line('  <fg=blue;options=bold>Starter Kit — System Check</>');
        $this->newLine();

        $tableStyle = new TableStyle;
        $tableStyle->setCellHeaderFormat('<options=bold>%s</>');

        $rows = array_map(function (DoctorReport $report) {
            return [
                $this->formatStatus($report->status),
                $report->name,
                $report->message,
                $report->hint !== '' ? '<fg=gray>'.$report->hint.'</>' : '',
            ];
        }, $reports);

        $this->table(
            ['Status', 'Check', 'Message', 'Hint'],
            $rows
        );

        $this->newLine();
        $this->printSummary($reports);
        $this->newLine();

        return $this->resolveExitCode($reports);
    }

    /**
     * @param  list<DoctorReport>  $reports
     */
    private function outputJson(array $reports): int
    {
        $data = [
            'version' => 1,
            'generated_at' => now()->toISOString(),
            'summary' => $this->buildSummary($reports),
            'checks' => array_map(fn (DoctorReport $r) => $r->toArray(), $reports),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->resolveExitCode($reports);
    }

    private function formatStatus(DoctorStatus $status): string
    {
        return match ($status) {
            DoctorStatus::Ok => '<fg=green>  OK  </>',
            DoctorStatus::Warn => '<fg=yellow> WARN </>',
            DoctorStatus::Fail => '<fg=red> FAIL </>',
        };
    }

    /** @param list<DoctorReport> $reports */
    private function printSummary(array $reports): void
    {
        $summary = $this->buildSummary($reports);

        $okColor = $summary['ok'] > 0 ? 'green' : 'gray';
        $warnColor = $summary['warn'] > 0 ? 'yellow' : 'gray';
        $failColor = $summary['fail'] > 0 ? 'red' : 'gray';

        $this->line(sprintf(
            '  <fg=%s>%d OK</> · <fg=%s>%d warning(s)</> · <fg=%s>%d error(s)</>',
            $okColor,
            $summary['ok'],
            $warnColor,
            $summary['warn'],
            $failColor,
            $summary['fail']
        ));
    }

    /**
     * @param  list<DoctorReport>  $reports
     * @return array{ok: int, warn: int, fail: int, total: int}
     */
    private function buildSummary(array $reports): array
    {
        $ok = count(array_filter($reports, fn (DoctorReport $r) => $r->isOk()));
        $warn = count(array_filter($reports, fn (DoctorReport $r) => $r->isWarn()));
        $fail = count(array_filter($reports, fn (DoctorReport $r) => $r->isFail()));

        return [
            'ok' => $ok,
            'warn' => $warn,
            'fail' => $fail,
            'total' => count($reports),
        ];
    }

    /** @param list<DoctorReport> $reports */
    private function resolveExitCode(array $reports): int
    {
        $hasFail = array_filter($reports, fn (DoctorReport $r) => $r->isFail()) !== [];
        $hasWarn = array_filter($reports, fn (DoctorReport $r) => $r->isWarn()) !== [];

        if ($hasFail) {
            return self::EXIT_FAIL; // 2
        }

        if ($hasWarn) {
            return self::EXIT_WARN; // 1
        }

        return Command::SUCCESS; // 0
    }
}
