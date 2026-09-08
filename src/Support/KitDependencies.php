<?php

namespace Lvntr\StarterKit\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * Detect kit-required Composer packages that are missing from the consumer app.
 *
 * The kit's own `composer.json` `require` block is the source of truth for what
 * `sk:install` / `sk:doctor` expect to find installed. A consumer that removed a
 * package (or a lockfile that drifted) should be caught here instead of failing
 * later with an opaque "class not found".
 *
 * Fails soft everywhere: this runs inside console commands, and an exception here
 * must never take down `sk:update`/`sk:doctor` for the operator.
 */
final class KitDependencies
{
    /**
     * Package names required by the kit but not installed in the consumer app.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        try {
            if (! class_exists(InstalledVersions::class)) {
                return [];
            }

            $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

            if (! is_readable($composerJsonPath)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($composerJsonPath), true);

            if (! is_array($decoded) || ! isset($decoded['require']) || ! is_array($decoded['require'])) {
                return [];
            }

            $missing = [];

            foreach (array_keys($decoded['require']) as $name) {
                if (! is_string($name) || $name === 'php' || str_starts_with($name, 'ext-')) {
                    continue;
                }

                if (! InstalledVersions::isInstalled($name)) {
                    $missing[] = $name;
                }
            }

            return $missing;
        } catch (Throwable) {
            return [];
        }
    }
}
