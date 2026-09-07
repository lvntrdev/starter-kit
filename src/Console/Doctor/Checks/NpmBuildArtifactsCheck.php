<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Vite build artifact'lerinin varlığını kontrol eder.
 * public/build/manifest.json yoksa frontend derlemesi yapılmamış demektir.
 */
class NpmBuildArtifactsCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.npm_build_artifacts.name');
    }

    public function run(): DoctorReport
    {
        $manifestPath = function_exists('public_path')
            ? public_path('build/manifest.json')
            : base_path('public/build/manifest.json');

        if (! file_exists($manifestPath)) {
            $env = config('app.env', app()->environment());

            if ($env === 'production') {
                return DoctorReport::fail(
                    $this->name(),
                    (string) __('sk-doctor.npm_build_artifacts.manifest_missing_production'),
                    (string) __('sk-doctor.npm_build_artifacts.manifest_missing_production_hint')
                );
            }

            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.npm_build_artifacts.manifest_missing'),
                (string) __('sk-doctor.npm_build_artifacts.manifest_missing_hint')
            );
        }

        // Manifest geçerli JSON mi?
        $content = file_get_contents($manifestPath);
        $decoded = json_decode($content ?: '', true);

        if (! is_array($decoded) || $decoded === []) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.npm_build_artifacts.manifest_invalid'),
                (string) __('sk-doctor.npm_build_artifacts.manifest_invalid_hint')
            );
        }

        $assetCount = count($decoded);

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.npm_build_artifacts.present', ['count' => $assetCount])
        );
    }
}
