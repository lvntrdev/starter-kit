<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * End-to-end proof that the three data-loss paths sk:install used to carry are
 * closed. Each `it()` below names the path it guards; a failure here is not a
 * style regression, it is one of these coming back:
 *
 *   1. A live application's .env overwritten by .env.example — the operator's
 *      DB credentials, APP_KEY and DATA_ENCRYPTION_KEY gone.
 *   2. An installed application classified as a first install because the
 *      git-ignored hash registry was lost, and every published path — edited
 *      controllers, Vue pages, config/permission-resources.php — force-copied
 *      over.
 *   3. A consumer-edited published file silently returned to its stub contents
 *      on a re-run.
 *
 * These run the REAL command against a throwaway application tree: base_path()
 * is repointed with setBasePath(), so storage_path() and every base_path()
 * lookup inside the command follow, and nothing can leak into the testbench
 * skeleton. The unit-level companions live in ExistingAppDetectionTest.php
 * (which marker fires on which tree) and PublishOverwriteGuardTest.php (the
 * three-hash decision rule); this file asserts what the operator actually gets:
 * an exit code and the bytes left on disk.
 *
 * Helpers carry an `ist` prefix — a Pest file declares its helpers at global
 * scope for the whole process, so bare names collide across files.
 */

/**
 * Run ensureEnvFile() with full console wiring.
 *
 * The .env branch decision is the Task 1 data-loss line and it lives in a
 * private method that a full sk:install run only reaches after migrations,
 * seeders and npm. Calling it by reflection on a bare `new InstallCommand`
 * fails on $this->components (null until the command is executed), so it is
 * driven here through a real Artisan command instead: same wiring, none of the
 * install's side effects.
 */
final class IstEnsureEnvRunner extends InstallCommand
{
    protected $signature = 'sk:test-ensure-env {--first-install}';

    protected $description = 'Test-only: run InstallCommand::ensureEnvFile() with real console wiring.';

    public function handle(): int
    {
        $method = new ReflectionMethod(InstallCommand::class, 'ensureEnvFile');
        $method->invoke($this, (bool) $this->option('first-install'));

        return self::SUCCESS;
    }
}

/**
 * Materialise a throwaway application tree, point the app at it, and give the
 * hash registry a path inside it. A key ending in `/` creates an empty
 * directory; anything else creates a file with the given contents.
 *
 * @param  array<string, string>  $tree
 */
function istBoot(array $tree): string
{
    $dir = sys_get_temp_dir().'/sk-installer-safety-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach ($tree as $relative => $contents) {
        $path = $dir.DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR);

        if (str_ends_with($relative, '/')) {
            $target = rtrim($path, DIRECTORY_SEPARATOR);
            is_dir($target) || mkdir($target, 0700, true);

            continue;
        }

        is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }

    // base_path() and storage_path() both follow, so every path the command
    // resolves lands inside the throwaway tree.
    app()->setBasePath($dir);
    config(['starter-kit.published_hashes' => $dir.'/storage/starter-kit/hashes.json']);

    $GLOBALS['ist_trees'][] = $dir;

    return $dir;
}

function istRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/**
 * Every file under $dir as `relative path => "size:mtime:md5"`.
 *
 * Contents AND mtime, because "wrote the identical bytes back" is still a write:
 * it destroys an operator's ability to tell what a run touched, and it is one
 * `--force` away from destroying the bytes too.
 *
 * @return array<string, string>
 */
function istSnapshot(string $dir): array
{
    $snapshot = [];

    if (! is_dir($dir)) {
        return $snapshot;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($items as $item) {
        if (! $item->isFile()) {
            continue;
        }

        $relative = substr($item->getPathname(), strlen($dir) + 1);
        clearstatcache(true, $item->getPathname());

        $snapshot[$relative] = sprintf(
            '%d:%d:%s',
            $item->getSize(),
            filemtime($item->getPathname()),
            md5_file($item->getPathname()),
        );
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * Run sk:install and return [exit code, full output].
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function istInstall(array $parameters = []): array
{
    $buffer = new BufferedOutput;
    $exit = Artisan::call('sk:install', $parameters, $buffer);

    return [$exit, $buffer->fetch()];
}

/**
 * The shape `laravel new` produces plus the composer.lock entry that
 * `composer require lvntr/laravel-starter-kit` writes immediately before the
 * operator's very first sk:install.
 *
 * @return array<string, string>
 */
function istStockLaravelTree(): array
{
    return [
        'composer.lock' => '{"packages":[{"name":"lvntr/laravel-starter-kit","version":"v13.6.16"}]}',
        'app/Models/User.php' => '<?php',
        'app/Providers/AppServiceProvider.php' => '<?php',
        'config/app.php' => '<?php return [];',
        'resources/js/app.js' => '// app',
    ];
}

/**
 * An installed application: two kit-published markers, plus the operator's own
 * .env holding the credentials this whole file exists to protect.
 *
 * @return array<string, string>
 */
function istInstalledAppTree(): array
{
    return istStockLaravelTree() + [
        'app/Http/Controllers/Admin/UserController.php' => '<?php // edited by the operator',
        'config/permission-resources.php' => '<?php return ["resources" => ["invoices"]];',
        '.env' => "APP_NAME=Acme\nAPP_KEY=base64:OPERATORKEY\nDB_PASSWORD=s3cr3t-prod\nDATA_ENCRYPTION_KEY=base64:DATAKEY\n",
        '.env.example' => istEnvExample(),
    ];
}

/**
 * A stand-in for the kit's .env.example.
 *
 * APP_KEY is deliberately non-blank: ensureAppKey() short-circuits on a
 * non-empty value, which keeps `key:generate` — a command that writes to the
 * application's own environment file — out of these tests. The real stub's
 * contents are pinned by EnvMergeTest; what is under test here is the branch
 * that decides between copying it and merging it.
 */
function istEnvExample(): string
{
    return implode("\n", [
        'APP_NAME=Laravel',
        'APP_KEY=base64:EXAMPLEKEY',
        'DB_PASSWORD=',
        '# CACHE_PREFIX=',
        'SESSION_DRIVER=database',
        'FILESYSTEM_DISK=local',
        'STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false',
        '',
    ]);
}

beforeEach(function (): void {
    // Every scenario in this file is about the FILESYSTEM boundary. The kit
    // schema marker has its own unit coverage in ExistingAppDetectionTest; here
    // it is pinned off so a table another test left behind cannot change which
    // markers fire. An unreachable database is also the honest shape of a first
    // install, which is run before DB_* is configured at all.
    config([
        'database.default' => 'ist_unreachable',
        'database.connections.ist_unreachable' => [
            'driver' => 'sqlite',
            'database' => '/ist-nonexistent-'.bin2hex(random_bytes(4)).'/app.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);
    DB::purge('ist_unreachable');

    $GLOBALS['ist_trees'] = [];
});

afterEach(function (): void {
    foreach ($GLOBALS['ist_trees'] ?? [] as $dir) {
        istRemove($dir);
    }

    $GLOBALS['ist_trees'] = [];
});

/*
|--------------------------------------------------------------------------
| Data-loss path 2 — an installed app taken for a first install
|--------------------------------------------------------------------------
*/

it('refuses to install over an application whose registry was lost, and writes nothing', function (): void {
    // THE regression. The registry under storage/ is git-ignored, so a
    // stateless deploy or a fresh clone loses it; before the guard this run
    // force-published over every path below and took the first-install-only
    // branches on an app full of data.
    $dir = istBoot(istInstalledAppTree());
    $before = istSnapshot($dir);

    [$exit, $output] = istInstall();

    expect($exit)->toBe(Command::FAILURE)
        // Not one byte: no file added, none removed, none rewritten, no mtime
        // moved — and no storage/ directory created on the way out either.
        ->and(istSnapshot($dir))->toBe($before)
        ->and(is_dir($dir.'/storage'))->toBeFalse();

    // The stop is only worth having if the operator can judge it in one read,
    // and can get out of it without hand-writing a registry.
    expect($output)
        ->toContain('app/Http/Controllers/Admin/')
        ->toContain('config/permission-resources.php')
        ->toContain('sk:install --adopt')
        ->toContain('sk:update');
});

it('leaves the .env of a stopped run byte-identical', function (): void {
    // Called out separately from the tree snapshot above because this is the
    // credential loss itself, not a file count.
    $dir = istBoot(istInstalledAppTree());
    $envBefore = file_get_contents($dir.'/.env');

    [$exit] = istInstall();

    expect($exit)->toBe(Command::FAILURE)
        ->and(file_get_contents($dir.'/.env'))->toBe($envBefore)
        ->and($envBefore)->toContain('DB_PASSWORD=s3cr3t-prod');
});

it('still lets a fresh project that merely required the package install', function (): void {
    // The backward-compatibility guard, and the failure mode that would cost
    // more than the bug: a false marker blocking the ordinary
    // `composer require` → `sk:install` flow. --dry-run is used so the assertion
    // is "the guard let it through and nothing was written", without running
    // migrations, seeders and npm inside a test.
    $dir = istBoot(istStockLaravelTree());
    $before = istSnapshot($dir);

    [$exit, $output] = istInstall(['--dry-run' => true]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->not->toContain('already looks installed')
        ->and($output)->toContain('Dry run')
        ->and(istSnapshot($dir))->toBe($before);
});

it('lets --force through the stop while still refusing to touch .env', function (): void {
    // --force keeps its escape-hatch meaning: the operator who reads the stop
    // and decides the evidence is a coincidence must have a way forward.
    $dir = istBoot(istInstalledAppTree());
    $envBefore = file_get_contents($dir.'/.env');

    [$exit, $output] = istInstall(['--force' => true, '--dry-run' => true]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('--force')
        // Named loudly rather than proceeding quietly.
        ->and($output)->toContain('app/Http/Controllers/Admin/')
        ->and(file_get_contents($dir.'/.env'))->toBe($envBefore);
});

/*
|--------------------------------------------------------------------------
| --adopt — the recovery path out of the stop
|--------------------------------------------------------------------------
*/

it('rebuilds the registry under --adopt and changes nothing else on disk', function (): void {
    $dir = istBoot(istInstalledAppTree());
    $before = istSnapshot($dir);

    [$exit, $output] = istInstall(['--adopt' => true]);

    $registryPath = $dir.'/storage/starter-kit/hashes.json';
    $registry = json_decode(file_get_contents($registryPath), true);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($registry)->toHaveKey('_format')
        // Recorded under the STUB hash, which is what makes a later sk:update
        // able to tell a consumer edit from an untouched file.
        ->and($registry)->toHaveKey('config/permission-resources.php')
        ->and($registry['config/permission-resources.php'])
        ->toBe(md5_file(dirname(__DIR__, 3).'/stubs/config/permission-resources.php'))
        ->and($output)->toContain('Nothing else on disk was touched.');

    // Every pre-existing file, byte for byte and mtime for mtime. The registry
    // is the ONE addition.
    $after = istSnapshot($dir);

    expect(array_keys(array_diff_key($after, $before)))->toBe(['storage/starter-kit/hashes.json'])
        ->and(array_intersect_key($after, $before))->toBe($before);
});

it('writes nothing at all under --adopt --dry-run, registry included', function (): void {
    $dir = istBoot(istInstalledAppTree());
    $before = istSnapshot($dir);

    [$exit, $output] = istInstall(['--adopt' => true, '--dry-run' => true]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Dry run')
        ->and(istSnapshot($dir))->toBe($before)
        ->and(file_exists($dir.'/storage/starter-kit/hashes.json'))->toBeFalse()
        // Not even the directory: a dry run that mutated anything would be
        // worse than no dry run at all.
        ->and(is_dir($dir.'/storage'))->toBeFalse();
});

it('refuses to adopt an application where no kit file was found', function (): void {
    // A registry listing zero files still EXISTS, and its existence is what
    // makes isFirstInstall() false — writing one here would permanently deny a
    // never-installed app the first-install steps, with no error to explain it.
    //
    // SCOPE OF THIS GUARD, stated so the test is not read as more than it is:
    // it fires on "no stub target exists on disk at all", not on "this app was
    // never installed". The tree below therefore holds only paths the kit does
    // NOT ship — a stock Laravel app owns app/Models/User.php and
    // app/Providers/AppServiceProvider.php, both of which the kit also ships,
    // so it clears the zero-count check.
    $dir = istBoot([
        'composer.lock' => '{"packages":[{"name":"lvntr/laravel-starter-kit","version":"v13.6.16"}]}',
        'config/app.php' => '<?php return [];',
        'resources/js/app.js' => '// app',
    ]);
    $before = istSnapshot($dir);

    [$exit, $output] = istInstall(['--adopt' => true]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('Nothing to adopt')
        ->and(istSnapshot($dir))->toBe($before)
        ->and(file_exists($dir.'/storage/starter-kit/hashes.json'))->toBeFalse();
});

it('backs the previous registry up before replacing it', function (): void {
    // A rebuild is a judgement call about files the operator may have edited,
    // so the state it replaced stays on disk — one `mv` from being undone.
    $dir = istBoot(array_merge(istInstalledAppTree(), [
        'storage/starter-kit/hashes.json' => '{"_format":"v2","kept":"previous"}',
    ]));

    [$exit] = istInstall(['--adopt' => true]);

    $backups = glob($dir.'/storage/starter-kit/hashes.json.bak-*');

    expect($exit)->toBe(Command::SUCCESS)
        ->and($backups)->toHaveCount(1)
        ->and(file_get_contents($backups[0]))->toBe('{"_format":"v2","kept":"previous"}')
        ->and(json_decode(file_get_contents($dir.'/storage/starter-kit/hashes.json'), true))
        ->not->toHaveKey('kept');
});

/*
|--------------------------------------------------------------------------
| Data-loss path 3 — a consumer edit returned to its stub contents
|--------------------------------------------------------------------------
*/

it('preserves a consumer edit on a re-run, keyed by the registry --adopt just wrote', function (): void {
    // The round trip is the point: --adopt writes the registry, the publish
    // loop reads it, and the two must key paths identically. If they drift
    // (getRelativePathname() vs the normalised form) every file reads as
    // "no record" and the overwrite guard silently never fires.
    $stub = file_get_contents(dirname(__DIR__, 3).'/stubs/config/permission-resources.php');
    $edited = $stub."\n// the operator's own resource\n";

    $dir = istBoot(array_merge(istInstalledAppTree(), [
        'config/permission-resources.php' => $edited,
    ]));

    [$adoptExit] = istInstall(['--adopt' => true]);
    expect($adoptExit)->toBe(Command::SUCCESS);

    // A newer kit ships a different version of that same path.
    $command = istPublish($dir, '<?php return ["resources" => ["shipped-by-the-kit"]];', force: false);

    expect(file_get_contents($dir.'/config/permission-resources.php'))->toBe($edited)
        ->and(istProperty($command, 'preserved'))->toContain('config/permission-resources.php')
        ->and(istProperty($command, 'published'))->toBeEmpty();
});

it('overwrites that same consumer edit only when --force is passed', function (): void {
    $stub = file_get_contents(dirname(__DIR__, 3).'/stubs/config/permission-resources.php');

    $edited = $stub."\n// the operator's own resource\n";

    $dir = istBoot(array_merge(istInstalledAppTree(), [
        'config/permission-resources.php' => $edited,
    ]));

    istInstall(['--adopt' => true]);
    expect(file_get_contents($dir.'/config/permission-resources.php'))->toBe($edited);

    $shipped = '<?php return ["resources" => ["shipped-by-the-kit"]];';
    $command = istPublish($dir, $shipped, force: true);

    expect(file_get_contents($dir.'/config/permission-resources.php'))->toBe($shipped)
        ->and(istProperty($command, 'preserved'))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Data-loss path 1 — the .env
|--------------------------------------------------------------------------
*/

it('merges kit keys into an existing .env instead of copying over it', function (): void {
    // The credential loss. `composer create-project` leaves a .env behind, so
    // the FIRST sk:install of a normal project used to hit the copy branch and
    // take DB_PASSWORD, APP_KEY and DATA_ENCRYPTION_KEY with it.
    $dir = istBoot(istInstalledAppTree());
    app(Kernel::class)->registerCommand(new IstEnsureEnvRunner);

    Artisan::call('sk:test-ensure-env', ['--first-install' => true], new BufferedOutput);

    $env = file_get_contents($dir.'/.env');

    expect($env)
        ->toContain('DB_PASSWORD=s3cr3t-prod')
        ->toContain('APP_KEY=base64:OPERATORKEY')
        ->toContain('DATA_ENCRYPTION_KEY=base64:DATAKEY')
        // Merged, not replaced: the keys the kit added are there too.
        ->toContain('SESSION_DRIVER=database')
        ->toContain('FILESYSTEM_DISK=local');

    // Each surviving key appears exactly once — a merge that appended a second
    // DB_PASSWORD= line would leave the LAST one winning, which is the same
    // credential loss by another route.
    expect(substr_count($env, 'DB_PASSWORD='))->toBe(1)
        ->and(substr_count($env, 'APP_KEY='))->toBe(1);
});

it('does not seed a first-install-only key into an existing .env on a re-run', function (): void {
    // An app that has been running for two years must not acquire a stricter
    // gate from a command it ran for an unrelated reason.
    $dir = istBoot(istInstalledAppTree());
    app(Kernel::class)->registerCommand(new IstEnsureEnvRunner);

    Artisan::call('sk:test-ensure-env', [], new BufferedOutput);

    expect(file_get_contents($dir.'/.env'))
        ->not->toContain('STARTER_KIT_ALLOW_UNRESOLVED_ROUTES')
        ->toContain('DB_PASSWORD=s3cr3t-prod');
});

it('seeds a brand new .env from the example when the application has none', function (): void {
    // The backward-compatibility guard for path 1: a genuine first install on
    // an app with no .env still gets the full file, first-install-only keys
    // included.
    $tree = istInstalledAppTree();
    unset($tree['.env']);
    $dir = istBoot($tree);
    app(Kernel::class)->registerCommand(new IstEnsureEnvRunner);

    Artisan::call('sk:test-ensure-env', ['--first-install' => true], new BufferedOutput);

    $env = file_get_contents($dir.'/.env');

    expect(file_exists($dir.'/.env'))->toBeTrue()
        ->and($env)->toContain('SESSION_DRIVER=database')
        ->and($env)->toContain('STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false')
        // A file that holds credentials is created private, not umask-wide.
        ->and(substr(sprintf('%o', fileperms($dir.'/.env')), -4))->toBe('0600');

    // No temp file survived the atomic write.
    expect(array_values(array_filter(
        scandir($dir),
        fn (string $entry): bool => str_starts_with($entry, '.env.sk-tmp-'),
    )))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Local helpers used by the publish round trip
|--------------------------------------------------------------------------
*/

/**
 * Publish a one-file source tree over the application at $dir, using the
 * registry currently configured, and return the command so its decision lists
 * can be inspected.
 *
 * A one-file source is used rather than the shipped stubs directory because
 * publishing the real tree would copy the whole scaffold into a temp dir on
 * every run; the registry, the destination and the decision rule are the real
 * ones either way.
 */
function istPublish(string $dir, string $shippedContents, bool $force): InstallCommand
{
    $source = sys_get_temp_dir().'/sk-installer-safety-src-'.bin2hex(random_bytes(6));
    mkdir($source.'/config', 0700, true);
    file_put_contents($source.'/config/permission-resources.php', $shippedContents);

    $command = new InstallCommand;
    // getSkipPaths() reads --without-ai-skill, so the loop needs a bound input.
    $command->setInput(new ArrayInput([], $command->getDefinition()));

    $method = new ReflectionMethod($command, 'publishDirectory');
    $method->invoke($command, $source, $dir, $force);

    istRemove($source);

    return $command;
}

/** @return list<string> */
function istProperty(InstallCommand $command, string $property): array
{
    $reflected = new ReflectionProperty($command, $property);

    /** @var list<string> */
    return $reflected->getValue($command);
}
