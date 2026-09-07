<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Log kanalını kontrol eder.
 * single driver: tek dosyaya yazar, production'da önerilmez (rotate edilmez, büyür).
 * daily driver: her gün yeni dosya oluşturur, önerilir.
 */
class LogChannelCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.log_channel.name');
    }

    public function run(): DoctorReport
    {
        // config(), not env(): once the configuration is cached, .env is not
        // loaded and env() would report the default instead of the effective
        // runtime setting.
        $channel = (string) config('logging.default', 'stack');

        if ($channel === 'single') {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.log_channel.single_unbounded'),
                (string) __('sk-doctor.log_channel.single_unbounded_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.log_channel.configured', ['channel' => $channel])
        );
    }
}
