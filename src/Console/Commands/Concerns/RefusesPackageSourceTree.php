<?php

namespace Lvntr\StarterKit\Console\Commands\Concerns;

use Lvntr\StarterKit\StarterKitServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refuses to run a file-writing kit command against the kit's OWN source tree.
 *
 * The package repository ships `vendor/bin/testbench`, so `vendor/bin/testbench
 * sk:install` (or sk:update / sk:publish / sk:eject / sk:upgrade / make:sk-domain
 * / remove:sk-domain / env:sync / encryption:key) boots a real application whose
 * base path is the Testbench skeleton living INSIDE the package checkout — and
 * whose vendor/ is a symlink back to the package's own vendor/. Those commands
 * then publish stubs, rewrite .env, merge package.json, generate domains, delete
 * files, run migrations and shell out to npm/composer against a directory that
 * is not an application at all. Nothing in the kit noticed: the already-installed
 * markers do not fire on a bare skeleton, so the run was classified as a pristine
 * first install and took the force-overwrite path.
 *
 * The guard is about WRITING to that tree, and only that: a mode that writes
 * nothing (--dry-run, encryption:key --show) is never blocked, and a
 * --destination pointing outside the checkout is a legitimate target that passes.
 *
 * This is not a --force decision. --force means "overwrite the files I named";
 * it never means "install the kit into the kit". There is no flag.
 */
trait RefusesPackageSourceTree
{
    /**
     * Directory a consumer app ALWAYS has once composer installed the kit —
     * including a `type: path` repository, where it is a symlink.
     */
    private const PACKAGE_VENDOR_DIR = 'vendor/lvntr/laravel-starter-kit';

    /**
     * The path the last check ran against, kept so the stop can name the tree it
     * actually refused rather than assuming the application base path.
     */
    private ?string $packageSourceTreeTarget = null;

    /**
     * True when this command must stop before writing anything.
     *
     * @param  string|null  $target  where the command would write; defaults to the
     *                               application base path, and takes a
     *                               `--destination=` override — which may name a
     *                               directory that does not exist yet
     */
    protected function isPackageSourceTree(?string $target = null): bool
    {
        $this->packageSourceTreeTarget = $target !== null && $target !== ''
            ? $target
            : $this->laravel->basePath();

        if ($this->runningPackageSuite()) {
            return false;
        }

        $packageRoot = $this->realPath(StarterKitServiceProvider::basePath());
        $writeRoot = $this->realPath($this->packageSourceTreeTarget);

        if ($packageRoot === null || $writeRoot === null) {
            return false;
        }

        // A consumer application — or a destination override — never lives
        // inside the package checkout.
        if ($writeRoot !== $packageRoot && ! str_starts_with($writeRoot, $packageRoot.DIRECTORY_SEPARATOR)) {
            return false;
        }

        // ...with one legitimate exception: a scratch application nested under
        // the checkout that actually REQUIRED the kit (its own vendor/ carries
        // the package, symlinked or copied). That is a real install target.
        return ! file_exists($writeRoot.DIRECTORY_SEPARATOR.self::PACKAGE_VENDOR_DIR);
    }

    /**
     * Explain the stop. Returns the command's exit code so a caller can
     * `return $this->renderPackageSourceTreeStop();` in one line.
     */
    protected function renderPackageSourceTreeStop(): int
    {
        $this->newLine();
        $this->components->error('Refusing to run inside the Starter Kit package itself — nothing was written.');
        $this->newLine();

        $this->line('  <fg=yellow>Where this ran:</>');
        $this->components->twoColumnDetail(
            '<fg=gray>package source</>',
            '<fg=white>'.StarterKitServiceProvider::basePath().'</>',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>write target</>',
            '<fg=white>'.($this->packageSourceTreeTarget ?? $this->laravel->basePath()).'</>',
        );

        $this->newLine();
        $this->line('  <fg=gray>The write target sits inside the package checkout, and that directory does not</>');
        $this->line('  <fg=gray>require the kit through composer ('.self::PACKAGE_VENDOR_DIR.' is missing).</>');
        $this->line('  <fg=gray>So this is the kit\'s own repository — most likely reached through</>');
        $this->line('  <fg=gray>vendor/bin/testbench, which boots a throwaway Laravel skeleton whose vendor/</>');
        $this->line('  <fg=gray>is a symlink back to this checkout. Continuing would write over the package</>');
        $this->line('  <fg=gray>sources.</>');

        $this->newLine();
        $this->line('  <fg=yellow>What to do instead:</>');
        $this->components->twoColumnDetail(
            '<fg=cyan>php artisan '.$this->getName().'</>',
            '<fg=gray>run it from inside the consumer application</>',
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>--destination=</><fg=gray> or </><fg=cyan>--dry-run</>',
            '<fg=gray>where the command offers them, both pass this guard</>',
        );

        $this->newLine();
        $this->line('  <fg=gray>--force does not bypass this: it means "overwrite the files I named", never</>');
        $this->line('  <fg=gray>"install the kit into the kit".</>');
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * The package's own suite drives these commands against the Testbench
     * skeleton deliberately — that IS the package tree, and refusing there
     * would fail every install/update/eject test in the repository. Kept as a
     * seam so the guard's path logic stays testable from inside that suite.
     *
     * Deliberately NOT `runningUnitTests()`: `vendor/bin/testbench` also boots
     * the app in the `testing` environment, and that is exactly the entry point
     * this guard exists to stop. PHPUnit's bootstrap constant is the only
     * signal that separates a test run from a hand-typed command.
     */
    protected function runningPackageSuite(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL')
            || class_exists(TestCase::class, false);
    }

    /**
     * The command's `--destination=` override, or null when it is absent or
     * empty — in which case the application base path is the write target, the
     * same fallback PublishCommand::resolveDestination() and
     * EjectCommand's destination handling apply.
     */
    private function destinationOption(): ?string
    {
        $value = $this->option('destination');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Resolve a path for comparison, falling back to its nearest EXISTING
     * ancestor. A `--destination=` names a directory the command is about to
     * create, so realpath() alone would return false for exactly the paths the
     * guard has to classify — and "false means allow" would hand the caller a
     * silent bypass: any not-yet-existing directory inside the checkout.
     */
    private function realPath(string $path): ?string
    {
        $candidate = $path;

        while (true) {
            $resolved = realpath($candidate);

            if ($resolved !== false) {
                return rtrim($resolved, DIRECTORY_SEPARATOR);
            }

            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return null;
            }

            $candidate = $parent;
        }
    }
}
