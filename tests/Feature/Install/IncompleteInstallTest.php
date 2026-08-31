<?php

use Illuminate\Console\Command;
use Lvntr\StarterKit\Console\Commands\InstallCommand;

require_once __DIR__.'/InstallDecisionHarness.php';

/**
 * The two ways sk:install used to close a run it had not actually finished.
 *
 *   1. The database was unreachable, so migrations, seeders and permission
 *      seeding never ran — and the command still wrote the hash registry,
 *      deleted the resume checkpoint, printed "installed successfully" and
 *      exited 0. A consumer CI went green over an application with no schema,
 *      no permissions and no admin user, and the `--resume` the same screen
 *      recommended had nothing left to resume from.
 *   2. Every run — first install or not — deleted package-lock.json,
 *      vite.config.* and resources/js/app.js. On a re-install those are the
 *      consumer's own files.
 *
 * Both are asserted at the level where the decision lives. Driving a real
 * end-to-end sk:install here is not an option: with --no-interaction it reaches
 * `npm install` and `composer dump-autoload`, so the run would be minutes long
 * and dependent on the machine's toolchain. The filesystem-boundary end-to-end
 * cases live in InstallerSafetyTest.
 *
 * The `ici*` helpers live in InstallDecisionHarness.php, required above — Pest
 * declares a test file's helpers at global scope for the whole process, but a
 * targeted `pest <one-file>` run loads only that one file, so a sibling that
 * borrowed them died on an undefined function.
 */
beforeEach(function (): void {
    $GLOBALS['ici_trees'] = [];
});

afterEach(function (): void {
    foreach ($GLOBALS['ici_trees'] ?? [] as $dir) {
        iciRemove($dir);
    }

    $GLOBALS['ici_trees'] = [];
});

// ── The unreachable-database verdict ───────────────────────────────────────

it('exits non-zero and keeps the checkpoint when the database steps never ran', function (): void {
    // THE regression: the exit code is what a consumer pipeline reads, and the
    // checkpoint is what the recommended `--resume` needs in order to skip the
    // filesystem steps instead of republishing over them.
    $dir = iciBoot();
    mkdir($dir.'/storage/starter-kit', 0700, true);
    $checkpoint = $dir.'/storage/starter-kit/install-progress.json';
    file_put_contents($checkpoint, json_encode(['completed' => ['Publishing application scaffolding']]));

    [$command, $output] = iciCommand(['installIncomplete' => true]);

    expect(iciInvoke($command, 'renderInstallSummary'))->toBe(Command::FAILURE);

    expect(file_exists($checkpoint))->toBeTrue();

    $text = $output->fetch();
    expect($text)
        ->toContain('INCOMPLETE')
        ->not->toContain('installed successfully')
        ->toContain('php artisan sk:install --resume');
});

it('withholds the hash registry write when the install is incomplete', function (): void {
    // handle() decides this BEFORE renderInstallSummary() ever runs — the
    // registry is the marker that says "this application is installed", and
    // writing it over a run whose database steps never fired would make the
    // NEXT sk:install read a half-installed app as a finished one, silencing
    // the very guard this regression protects. Driving the real handle() here
    // is not an option (see the file docblock: it reaches npm/composer), so
    // the guard is asserted at the source — a renamed/removed guard fails this
    // test instead of shipping a registry write with no schema behind it.
    $source = (string) file_get_contents(
        (new ReflectionClass(InstallCommand::class))->getFileName(),
    );

    expect($source)->toMatch(
        '/if \(! \$this->installIncomplete\) \{\s*\$this->saveStubHashes\(\);\s*\}/',
    );
});

it('clears the checkpoint and exits zero when the install really did finish', function (): void {
    // The backward-compatibility half: a complete run must still close exactly
    // as it did, or every green install turns red.
    $dir = iciBoot();
    mkdir($dir.'/storage/starter-kit', 0700, true);
    $checkpoint = $dir.'/storage/starter-kit/install-progress.json';
    file_put_contents($checkpoint, json_encode(['completed' => ['Publishing application scaffolding']]));

    [$command, $output] = iciCommand();

    expect(iciInvoke($command, 'renderInstallSummary'))->toBe(Command::SUCCESS);
    expect(file_exists($checkpoint))->toBeFalse();
    expect($output->fetch())->toContain('installed successfully')->not->toContain('INCOMPLETE');
});

// ── Conflicting default files ──────────────────────────────────────────────

it('deletes the stock Laravel conflicts on a first install', function (): void {
    $dir = iciBoot([
        'package-lock.json' => '{}',
        'vite.config.js' => '// vite',
        'resources/js/app.js' => '// app',
        'resources/views/welcome.blade.php' => 'welcome',
    ]);

    [$command] = iciCommand();
    iciInvoke($command, 'removeConflictingDefaults', [true]);

    expect(file_exists($dir.'/package-lock.json'))->toBeFalse()
        ->and(file_exists($dir.'/vite.config.js'))->toBeFalse()
        ->and(file_exists($dir.'/resources/js/app.js'))->toBeFalse()
        ->and(file_exists($dir.'/resources/views/welcome.blade.php'))->toBeFalse()
        ->and(iciProperty($command, 'conflictingFilesKept'))->toBe([]);
});

it('keeps and reports those same files on a re-install', function (): void {
    // package-lock.json pins the consumer's whole dependency tree and
    // vite.config.js carries build config they added — neither is the
    // installer's to delete once the application exists.
    $dir = iciBoot([
        'package-lock.json' => '{"lockfileVersion":3}',
        'vite.config.js' => '// the operator\'s own config',
        'resources/js/app.js' => '// app',
    ]);

    [$command, $output] = iciCommand();
    iciInvoke($command, 'removeConflictingDefaults', [false]);

    expect(file_get_contents($dir.'/package-lock.json'))->toBe('{"lockfileVersion":3}')
        ->and(file_get_contents($dir.'/vite.config.js'))->toBe('// the operator\'s own config')
        ->and(file_exists($dir.'/resources/js/app.js'))->toBeTrue();

    expect(iciProperty($command, 'conflictingFilesKept'))
        ->toContain('package-lock.json')
        ->toContain('vite.config.js')
        ->toContain('resources/js/app.js');

    // Silence would read as "there was nothing to do", which is the opposite of
    // the truth — the operator is the one who has to decide about these.
    iciInvoke($command, 'printConflictingFilesKept');
    expect($output->fetch())->toContain('package-lock.json')->toContain('Kept');
});

it('records nothing when a re-install finds no conflicting file', function (): void {
    iciBoot();

    [$command, $output] = iciCommand();
    iciInvoke($command, 'removeConflictingDefaults', [false]);

    expect(iciProperty($command, 'conflictingFilesKept'))->toBe([]);

    iciInvoke($command, 'printConflictingFilesKept');
    expect($output->fetch())->toBe('');
});

// ── The lockfile through installFrontend() ─────────────────────────────────

it('deletes the lockfile before a first install resolves dependencies', function (): void {
    // A lock left over from an unrelated package.json pins versions the kit's
    // own package.json was never resolved against, and on a first install there
    // is nothing of the operator's in it.
    $dir = iciBoot(['package-lock.json' => '{"lockfileVersion":3}']);

    [$command] = iciCommand();
    iciInvoke($command, 'prepareLockFile', [$dir.'/package-lock.json', true]);

    expect(file_exists($dir.'/package-lock.json'))->toBeFalse();
});

it('keeps the lockfile on a re-install and says so', function (): void {
    // removeConflictingDefaults() already reported this file as kept; deleting
    // it here would make that report a lie and re-resolve the whole dependency
    // graph onto versions the app was never tested against.
    $dir = iciBoot(['package-lock.json' => '{"lockfileVersion":3}']);

    [$command, $output] = iciCommand();
    iciInvoke($command, 'prepareLockFile', [$dir.'/package-lock.json', false]);

    expect(file_get_contents($dir.'/package-lock.json'))->toBe('{"lockfileVersion":3}')
        ->and($output->fetch())->toContain('package-lock.json');
});

it('does nothing when there is no lockfile to decide about', function (): void {
    $dir = iciBoot();

    [$command, $output] = iciCommand();
    iciInvoke($command, 'prepareLockFile', [$dir.'/package-lock.json', false]);

    expect(file_exists($dir.'/package-lock.json'))->toBeFalse()
        ->and($output->fetch())->toBe('');
});

it('clears node_modules only when there is a tree to clear', function (): void {
    $dir = iciBoot();
    mkdir($dir.'/node_modules/.bin', 0777, true);
    file_put_contents($dir.'/node_modules/.bin/vite', '#!/bin/sh');

    [$command, $output] = iciCommand();
    iciInvoke($command, 'clearNodeModules', [$dir.'/node_modules']);

    expect(is_dir($dir.'/node_modules'))->toBeFalse()
        ->and($output->fetch())->toContain('node_modules');

    // A second call has nothing to say and nothing to delete.
    [$command, $output] = iciCommand();
    iciInvoke($command, 'clearNodeModules', [$dir.'/node_modules']);

    expect($output->fetch())->toBe('');
});

it('clears node_modules inside the npm step, not in front of it', function (): void {
    // The regression this pins: the delete used to sit BEFORE the step and ran
    // only when a tree already existed. On a FIRST run there is none, so the
    // delete was never checkpointed — but the npm install that CREATED the tree
    // was. A `--resume` after an interruption therefore deleted the freshly
    // installed node_modules and then skipped the checkpointed install that
    // would have refilled it, leaving `npm run build` to fail on missing
    // dependencies. Inside the step's closure the two are tied together: skip
    // the step, skip the delete.
    //
    // Asserted at the source because installFrontend() shells out to npm and
    // cannot be driven here (see the file docblock).
    $source = (string) file_get_contents(
        (new ReflectionClass(InstallCommand::class))->getFileName(),
    );

    expect($source)->toMatch(
        '/Installing npm dependencies\'.*?\{\s*\$this->clearNodeModules\(/s',
    );

    // And nothing deletes the tree outside that closure.
    expect(substr_count($source, '$this->clearNodeModules('))->toBe(1);
});

it('decides the lockfile inside the npm step, not in front of it', function (): void {
    // The regression this pins: with the delete sitting BEFORE the step, a
    // `--resume` run removed the lockfile that the first run's `npm install`
    // had just written, and then skipped the checkpointed npm step — so
    // nothing regenerated it. Calling it from inside the step's closure ties
    // the two together: skip the step, skip the lockfile decision.
    //
    // Asserted at the source because installFrontend() shells out to npm and
    // cannot be driven here (see the file docblock).
    $source = (string) file_get_contents(
        (new ReflectionClass(InstallCommand::class))->getFileName(),
    );

    // The closure may run other pre-steps first (node_modules is cleared there
    // too, for the same reason), so the assertion is "inside the closure,
    // before npm is shelled out to" rather than "the first statement".
    expect($source)->toMatch(
        '/Installing npm dependencies\'.*?\{[^}]*?\$this->prepareLockFile\([^}]*?runProcessStep\(\[\'npm\', \'install\'\]/s',
    );
});
