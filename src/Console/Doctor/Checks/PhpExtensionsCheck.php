<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Starter Kit'in gerektirdiği PHP extension'larını kontrol eder.
 */
class PhpExtensionsCheck implements DoctorCheck
{
    /** @var list<string> */
    private array $required = [
        'gd',
        'intl',
        'pdo_mysql',
        'redis',
        'fileinfo',
        'openssl',
    ];

    public function name(): string
    {
        return (string) __('sk-doctor.php_extensions.name');
    }

    public function run(): DoctorReport
    {
        $missing = array_filter($this->required, fn (string $ext) => ! extension_loaded($ext));

        if ($missing === []) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.php_extensions.all_loaded', ['extensions' => implode(', ', $this->required)])
            );
        }

        return DoctorReport::fail(
            $this->name(),
            (string) __('sk-doctor.php_extensions.missing', ['extensions' => implode(', ', $missing)]),
            (string) __('sk-doctor.php_extensions.missing_hint')
        );
    }
}
