<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Mail driver konfigürasyonunu kontrol eder.
 * smtp driver için 2 saniye TCP ping dener.
 * log/array driver production'da uyarı üretir.
 */
class MailDriverCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.mail_driver.name');
    }

    public function run(): DoctorReport
    {
        $mailer = config('mail.default', 'log');
        $transport = config("mail.mailers.{$mailer}.transport", $mailer);

        if (in_array($transport, ['log', 'array'], true)) {
            $env = config('app.env', app()->environment());

            if ($env === 'production') {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.mail_driver.log_array_production', ['transport' => $transport]),
                    (string) __('sk-doctor.mail_driver.log_array_production_hint')
                );
            }

            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.mail_driver.log_array_non_production', ['transport' => $transport]),
                (string) __('sk-doctor.mail_driver.log_array_non_production_hint')
            );
        }

        if ($transport === 'smtp') {
            $host = config("mail.mailers.{$mailer}.host", '');
            $port = (int) config("mail.mailers.{$mailer}.port", 587);

            if (empty($host)) {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.mail_driver.smtp_host_missing'),
                    (string) __('sk-doctor.mail_driver.smtp_host_missing_hint')
                );
            }

            // 2 saniye TCP ping
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                2.0
            );

            if ($socket === false) {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.mail_driver.smtp_unreachable', [
                        'host' => $host,
                        'port' => $port,
                        'error' => $errstr,
                    ]),
                    (string) __('sk-doctor.mail_driver.smtp_unreachable_hint')
                );
            }

            fclose($socket);

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.mail_driver.smtp_connected', ['host' => $host, 'port' => $port])
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.mail_driver.configured', ['transport' => $transport])
        );
    }
}
