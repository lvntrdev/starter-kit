<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Node.js runtime sürümünü kontrol eder. Floor, frontend toolchain'in gerçek
 * motor gereksinimidir: stubs Vite 7 kullanır → `node ^20.19.0 || >=22.12.0`.
 * Salt-major "18+" kontrolü yanıltıcı OK verirdi (Node 18 / 20.18 geçer ama
 * `npm run build` sonradan patlar). `node` PATH'te yoksa ya da sürüm parse
 * edilemezse warn üretir — Node yalnızca asset derlemesi için gerekli
 * olduğundan runtime'ı bloke etmez.
 */
class NodeVersionCheck implements DoctorCheck
{
    // Vite 7 engine floor: "^20.19.0 || >=22.12.0" (Node 21 dahil değil).
    private const MIN_LABEL = '20.19+ / 22.12+';

    /**
     * Vite 7'nin "^20.19.0 || >=22.12.0" semver aralığını birebir uygular.
     */
    private static function meetsFloor(int $major, int $minor): bool
    {
        return ($major === 20 && $minor >= 19)
            || ($major === 22 && $minor >= 12)
            || $major > 22;
    }

    public function name(): string
    {
        return (string) __('sk-doctor.node_version.name');
    }

    public function run(): DoctorReport
    {
        try {
            $process = new Process(['node', '-v'], null, null, null, 5.0);
            $process->run();
        } catch (Throwable $e) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.node_version.exec_failed', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.node_version.exec_failed_hint', ['min_label' => self::MIN_LABEL])
            );
        }

        if (! $process->isSuccessful()) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.node_version.not_installed'),
                (string) __('sk-doctor.node_version.not_installed_hint', ['min_label' => self::MIN_LABEL])
            );
        }

        $raw = trim($process->getOutput());

        // Beklenen format: v18.17.0
        if (! preg_match('/v?(\d+)\.(\d+)\.(\d+)/', $raw, $m)) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.node_version.parse_failed', ['raw' => $raw]),
                (string) __('sk-doctor.node_version.parse_failed_hint')
            );
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];
        $version = "v{$m[1]}.{$m[2]}.{$m[3]}";

        if (! self::meetsFloor($major, $minor)) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.node_version.below_floor', ['version' => $version, 'min_label' => self::MIN_LABEL]),
                (string) __('sk-doctor.node_version.below_floor_hint', ['min_label' => self::MIN_LABEL])
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.node_version.meets_floor', ['version' => $version, 'min_label' => self::MIN_LABEL])
        );
    }
}
