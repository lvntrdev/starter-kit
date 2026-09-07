<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * public/storage sembolik linkinin varlığını ve geçerliliğini kontrol eder.
 */
class StorageSymlinkCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.storage_symlink.name');
    }

    public function run(): DoctorReport
    {
        $publicPath = function_exists('public_path') ? public_path('storage') : base_path('public/storage');

        if (! file_exists($publicPath) && ! is_link($publicPath)) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.storage_symlink.missing'),
                (string) __('sk-doctor.storage_symlink.missing_hint')
            );
        }

        if (is_link($publicPath) && ! file_exists($publicPath)) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.storage_symlink.broken'),
                (string) __('sk-doctor.storage_symlink.broken_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.storage_symlink.valid')
        );
    }
}
