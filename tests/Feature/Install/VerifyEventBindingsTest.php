<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Write a DomainServiceProvider stub into the testbench app's base_path so
 * verifyEventBindingsInjected() finds it. Returns a cleanup callable.
 */
function writeProvider(string $content): Closure
{
    $fs = new Filesystem;
    $dir = base_path('app/Providers');
    $path = $dir.'/DomainServiceProvider.php';

    $existed = $fs->exists($dir);
    $fs->makeDirectory($dir, 0755, true, true);
    $fs->put($path, $content);

    return function () use ($fs, $dir, $path, $existed): void {
        $fs->delete($path);
        if (! $existed) {
            $fs->deleteDirectory($dir);
        }
    };
}

/**
 * Invoke verifyEventBindingsInjected() on an InstallCommand instance and
 * return the captured output text.
 */
function captureVerify(string $domain): string
{
    $command = new InstallCommand;

    // $files is assigned inside handle() — initialize it directly via reflection
    // so the private property is set before verifyEventBindingsInjected() runs.
    // Initialize $files (assigned lazily inside handle()).
    $filesProperty = new ReflectionProperty($command, 'files');
    $filesProperty->setValue($command, new Filesystem);

    $output = new BufferedOutput;
    $style = new OutputStyle(
        new ArrayInput([], $command->getDefinition()),
        $output,
    );
    $command->setOutput($style);

    // Initialize $components (set in Command::execute() → run()).
    $componentsProperty = new ReflectionProperty($command, 'components');
    $componentsProperty->setValue($command, new ComponentsFactory($style));

    $method = new ReflectionMethod($command, 'verifyEventBindingsInjected');
    $method->invoke($command, $domain);

    return $output->fetch();
}

// ── Positive: marker FQCN present → no warning ────────────────────────────

it('emits no warning when User event marker is present in DomainServiceProvider', function (): void {
    $cleanup = writeProvider(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class DomainServiceProvider extends ServiceProvider {
    public function boot(): void {
        \Illuminate\Support\Facades\Event::listen(\App\Domain\User\Events\UserCreated::class, \App\Domain\User\Listeners\LogUserCreated::class);
    }
}
PHP);

    $output = captureVerify('User');
    $cleanup();

    expect($output)->not->toContain('Event bindings');
});

it('emits no warning when Role event marker is present in DomainServiceProvider', function (): void {
    $cleanup = writeProvider(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class DomainServiceProvider extends ServiceProvider {
    public function boot(): void {
        \Illuminate\Support\Facades\Event::listen(\App\Domain\Role\Events\RoleCreated::class, \App\Domain\Role\Listeners\LogRoleCreated::class);
    }
}
PHP);

    $output = captureVerify('Role');
    $cleanup();

    expect($output)->not->toContain('Event bindings');
});

// ── Negative: marker FQCN absent → warning emitted ────────────────────────

it('emits a warning when User event marker is missing from DomainServiceProvider', function (): void {
    $cleanup = writeProvider(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class DomainServiceProvider extends ServiceProvider {
    public function boot(): void {
        // no bindings
    }
}
PHP);

    $output = captureVerify('User');
    $cleanup();

    expect($output)->toContain('Event bindings');
    expect($output)->toContain('User');
});

it('emits a warning when Role event marker is missing from DomainServiceProvider', function (): void {
    $cleanup = writeProvider(<<<'PHP'
<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class DomainServiceProvider extends ServiceProvider {
    public function boot(): void {
        // no bindings
    }
}
PHP);

    $output = captureVerify('Role');
    $cleanup();

    expect($output)->toContain('Event bindings');
    expect($output)->toContain('Role');
});

// ── Edge: provider missing → warning about missing file ───────────────────

it('emits a warning about DomainServiceProvider when the file does not exist', function (): void {
    // Ensure the provider file does NOT exist at the testbench base_path.
    $fs = new Filesystem;
    $path = base_path('app/Providers/DomainServiceProvider.php');
    $backup = null;

    if ($fs->exists($path)) {
        $backup = $fs->get($path);
        $fs->delete($path);
    }

    $output = captureVerify('User');

    // Restore if it existed before this test.
    if ($backup !== null) {
        $fs->makeDirectory(dirname($path), 0755, true, true);
        $fs->put($path, $backup);
    }

    expect($output)->toContain('DomainServiceProvider');
});
