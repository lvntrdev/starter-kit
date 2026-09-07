<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;

/**
 * Tema-resolver çıktısının (_active.css) varlığını VE tutarlılığını kontrol eder.
 *
 * resources/css/theme/theme.css, resolver tarafından üretilen
 * `_active.css`'i @import eder. Bu artefakt gitignore'lu (build sırasında
 * kit tema resolver'ı üretir — sk-theme-build.mjs, vendor-resident); eksikse `npm run build`/`vite build`
 * "Can't resolve './_active.css'" ile hard-fail eder → bu durum Fail.
 *
 * Manifest mevcutsa içeriği de denetlenir (bkz. inspectManifest): tema kökü
 * dışına çıkan (`../`) bir @import → Warn (build'i bozmaz, dikkat çeker).
 *
 * Aktif tema artık `.sk-active-theme` marker'ı (admin Görünüm seçimi) ile
 * çözülür; VITE_SK_THEME tutarlılık kontrolü kasıtlı olarak yapılmaz — marker
 * seçimi env değerinden meşru biçimde farklı olabilir ve yanlış pozitif üretir.
 *
 * Tema-resolver sistemini henüz almamış (theme.css `_active.css` import
 * etmeyen) eski consumer'larda check uygulanmaz → Ok döner.
 */
class ThemeManifestCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.theme_manifest.name');
    }

    public function run(): DoctorReport
    {
        $themeEntryPath = base_path('resources/css/theme/theme.css');

        // theme.css yok ya da _active.css import etmiyor → tema-resolver
        // sistemi kullanımda değil (eski/migrate olmamış consumer) → uygulanmaz.
        if (! file_exists($themeEntryPath) || ! $this->importsActiveManifest($themeEntryPath)) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.theme_manifest.not_in_use')
            );
        }

        $activeManifestPath = base_path('resources/css/theme/_active.css');

        if (! file_exists($activeManifestPath)) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.theme_manifest.manifest_missing'),
                (string) __('sk-doctor.theme_manifest.manifest_missing_hint')
            );
        }

        return $this->inspectManifest($activeManifestPath);
    }

    /**
     * Üretilmiş _active.css içeriğini denetler: tema kökü dışına çıkan (`../`)
     * bir @import var mı — bu, resolver'ın tema-adı doğrulamasına (resolver'ın
     * `resolveThemeName`'i, vendor-resident) EK, bağımsız bir güvenlik ağıdır;
     * elle düzenlenmiş ya da eski/güvensiz bir resolver'dan kalmış stale
     * artefaktı yakalar. Sorun Warn'dır (build'i bozmaz); tek hard-fail eksik
     * manifest.
     */
    protected function inspectManifest(string $activeManifestPath): DoctorReport
    {
        $contents = (string) file_get_contents($activeManifestPath);

        // Tema dizininden çıkan import (traversal) — bağımsız güvenlik ağı.
        if (preg_match('#@import\s+[\'"][^\'"]*\.\./#', $contents) === 1) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.theme_manifest.traversal'),
                (string) __('sk-doctor.theme_manifest.traversal_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.theme_manifest.present')
        );
    }

    /**
     * theme.css içinde `_active.css` import satırı var mı?
     */
    private function importsActiveManifest(string $themeEntryPath): bool
    {
        $contents = file_get_contents($themeEntryPath);

        if ($contents === false) {
            return false;
        }

        return str_contains($contents, '_active.css');
    }
}
