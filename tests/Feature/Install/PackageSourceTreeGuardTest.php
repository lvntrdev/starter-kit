<?php

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;
use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * The guard that stops sk:install / sk:update / sk:publish / sk:eject /
 * sk:upgrade from running against the kit's OWN checkout — the shape reached
 * with `vendor/bin/testbench sk:install`, which boots a throwaway Laravel
 * skeleton inside the package and would otherwise publish stubs, rewrite .env
 * and run migrations over the package sources.
 *
 * Inside this suite the guard is inert by design (runningPackageSuite()), so
 * every path case is exercised through a double that reopens that seam.
 */
function pstgProbe(string $basePath, ?string $target = null): bool
{
    $command = new class extends Command
    {
        use RefusesPackageSourceTree;

        protected function runningPackageSuite(): bool
        {
            return false;
        }

        public function probe(?string $target): bool
        {
            return $this->isPackageSourceTree($target);
        }
    };

    $app = app();
    $original = $app->basePath();

    try {
        $app->setBasePath($basePath);
        $command->setLaravel($app);

        return $command->probe($target);
    } finally {
        $app->setBasePath($original);
    }
}

it('refuses a base path inside the package checkout', function () {
    // The live Testbench skeleton: inside the package, no vendor/lvntr of its own.
    expect(pstgProbe(base_path()))->toBeTrue();
});

it('refuses the package root itself', function () {
    expect(pstgProbe(StarterKitServiceProvider::basePath()))->toBeTrue();
});

it('allows a real application outside the package checkout', function () {
    $outside = sys_get_temp_dir().'/sk-guard-outside-'.uniqid();
    (new Filesystem)->makeDirectory($outside, 0755, true);

    try {
        expect(pstgProbe($outside))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('allows a nested app that actually requires the kit through composer', function () {
    $files = new Filesystem;
    $nested = StarterKitServiceProvider::basePath('.sk-guard-nested-'.uniqid());
    $files->makeDirectory($nested.'/vendor/lvntr/laravel-starter-kit', 0755, true);

    try {
        expect(pstgProbe($nested))->toBeFalse();
    } finally {
        $files->deleteDirectory($nested);
    }
});

it('stays inert while the package suite is running', function () {
    $command = new class extends Command
    {
        use RefusesPackageSourceTree;

        public function probe(): bool
        {
            return $this->isPackageSourceTree();
        }
    };

    $command->setLaravel(app());

    // Same base path the first case refuses — the seam is what differs.
    expect($command->probe())->toBeFalse();
});

it('refuses a destination that does not exist yet inside the package checkout', function () {
    // realpath() alone returns false here — the nearest existing ancestor decides.
    $target = StarterKitServiceProvider::basePath('.sk-guard-missing/nested/deeper');

    expect(pstgProbe(base_path(), $target))->toBeTrue();
});

it('allows a destination pointing outside the package checkout', function () {
    $outside = sys_get_temp_dir().'/sk-guard-destination-'.uniqid();

    // Deliberately NOT created: a --destination names a directory the command
    // is about to make.
    expect(pstgProbe(base_path(), $outside))->toBeFalse();
});
