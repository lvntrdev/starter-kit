<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Commands\RedactActivityLogSecretsCommand;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Are there activity-log rows that still carry a credential?
 *
 * The write-path guard (`HasActivityLogging`) is vendor-resident, so a plain
 * `composer update` closes the leak for NEW rows immediately and silently. The
 * cleanup half — the data migration that strips credentials out of rows written
 * before the deny list existed — only runs on `php artisan migrate`. An
 * operator who updated the package but never migrated therefore sees no error
 * anywhere while every historical password hash stays readable on the
 * activity-log screen. This check is what surfaces that.
 *
 * It calls `RedactActivityLogSecretsCommand::probe()`: read-only, bounded to a
 * fixed number of rows, and case-insensitive because the sensitivity decision
 * runs in PHP rather than under a database collation. A non-zero finding is a
 * FAIL, not a warn — an exposed credential is not a style issue.
 *
 * What it deliberately does NOT call is `redact(dryRun: true)`, which answers
 * the same question exactly but cannot be trusted inside a five-second budget:
 * its pre-filter misses a `Password`-cased key on MySQL/MariaDB (reporting OK
 * over a dirty row, which is worse than not checking at all), and without the
 * pre-filter — PostgreSQL and every other driver — it decodes the entire table.
 * probe() carries the reasoning for the shape that replaced it.
 *
 * The cost of a bounded probe is that it cannot prove a large table clean, and
 * the messages below say so instead of implying a total: a positive finding is
 * reported as a floor ("at least N"), and a clean bounded result names the
 * window it covered and points at the exhaustive command. Only when the probe
 * saw the whole table does it speak in absolute counts.
 */
class ActivityLogSecretsCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.activity_log_secrets.name');
    }

    public function run(): DoctorReport
    {
        try {
            $stats = RedactActivityLogSecretsCommand::probe();
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.activity_log_secrets.probe_failed', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.activity_log_secrets.probe_failed_hint')
            );
        }

        if ($stats['table'] === null) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.activity_log_secrets.no_table')
            );
        }

        if ($stats['columns'] === []) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.activity_log_secrets.no_json_column', ['table' => $stats['table']])
            );
        }

        if ($stats['dirty'] > 0) {
            return DoctorReport::fail(
                $this->name(),
                $stats['exhaustive']
                    ? (string) __('sk-doctor.activity_log_secrets.dirty_exhaustive', [
                        'count' => $stats['dirty'],
                        'table' => $stats['table'],
                    ])
                    : (string) __('sk-doctor.activity_log_secrets.dirty_bounded', [
                        'count' => $stats['dirty'],
                        'table' => $stats['table'],
                        'scanned' => $stats['scanned'],
                    ]),
                $stats['exhaustive']
                    ? (string) __('sk-doctor.activity_log_secrets.dirty_exhaustive_hint')
                    : (string) __('sk-doctor.activity_log_secrets.dirty_bounded_hint')
            );
        }

        if ($stats['invalid'] > 0) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.activity_log_secrets.invalid_json', [
                    'count' => $stats['invalid'],
                    'table' => $stats['table'],
                ]),
                (string) __('sk-doctor.activity_log_secrets.invalid_json_hint')
            );
        }

        // Two different statements, because they are not equally strong: the
        // first rules out a credential in that table, the second only in the
        // window the probe could afford to read.
        return DoctorReport::ok(
            $this->name(),
            $stats['exhaustive']
                ? (string) __('sk-doctor.activity_log_secrets.clean_exhaustive', [
                    'table' => $stats['table'],
                    'scanned' => $stats['scanned'],
                ])
                : (string) __('sk-doctor.activity_log_secrets.clean_bounded', [
                    'scanned' => $stats['scanned'],
                    'table' => $stats['table'],
                ])
        );
    }
}
