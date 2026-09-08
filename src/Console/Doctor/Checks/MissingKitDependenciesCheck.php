<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\Support\KitDependencies;

/**
 * Kit'in `composer.json` `require` bloğunda listelenen ama consumer app'te
 * kurulu olmayan paketleri tespit eder. Tespit mantığı `KitDependencies`'te
 * tekilleştirilmiştir; burada yalnızca `DoctorReport`'a çevrilir.
 */
class MissingKitDependenciesCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.missing_kit_dependencies.name');
    }

    public function run(): DoctorReport
    {
        $missing = KitDependencies::missing();

        if ($missing === []) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.missing_kit_dependencies.all_installed')
            );
        }

        return DoctorReport::fail(
            $this->name(),
            (string) __('sk-doctor.missing_kit_dependencies.missing', ['packages' => implode(', ', $missing)]),
            (string) __('sk-doctor.missing_kit_dependencies.missing_hint')
        );
    }
}
