<?php

/*
|--------------------------------------------------------------------------
| encryption:health — regression for the cached-config verdict fail-closed
|--------------------------------------------------------------------------
|
| EncrypterCoverageTest.php already exercises the divergence signal through a
| REAL PROCESS ENV VAR ($_SERVER). This file drives the same signal through a
| REAL .env FILE on disk instead — a distinct code path inside
| EncrypterCoverage::environmentFileValue()/environmentFileContents() that the
| $_SERVER-only fixtures never touch — and adds the one branch neither existing
| suite exercises: a cached config whose .env file exists but cannot be READ
| (permission-denied), as opposed to a chain that simply resolves to nothing.
|
| Helpers carry an `ehvr` prefix — Pest helpers are global for the whole
| process, so bare names collide across files.
|
*/

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

function ehvrBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function ehvrKey(string $seed): string
{
    return 'base64:'.base64_encode(ehvrBytes($seed));
}

function ehvrEncrypter(string $seed): Encrypter
{
    return new Encrypter(ehvrBytes($seed), 'AES-256-CBC');
}

function ehvrConfigureKeys(string $primarySeed = 'primary'): void
{
    config([
        'app.key' => ehvrKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption' => [
            'key' => ehvrKey($primarySeed),
            'previous_keys' => null,
            'cipher' => null,
        ],
    ]);

    app(DataEncrypterFactory::class)->flush();
}

function ehvrCreateTables(): void
{
    Schema::create('settings', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('group')->nullable();
        $table->string('key');
        $table->text('value')->nullable();
        $table->boolean('encrypted')->default(false);
    });

    Schema::create('users', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('email')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
    });
}

/**
 * @return array{status: int, output: string}
 */
function ehvrHealth(array $parameters = []): array
{
    $status = Artisan::call('encryption:health', $parameters);

    return ['status' => $status, 'output' => Artisan::output()];
}

/**
 * @return array<string, mixed>
 */
function ehvrJson(): array
{
    $result = ehvrHealth(['--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['exit_code'])->toBe($result['status']);

    return $decoded;
}

/**
 * Make Application::configurationIsCached() answer true for this test — binds
 * the memo the framework itself reads, matching EncrypterCoverageTest's
 * ecvPretendConfigIsCached(). A cache FILE on disk would not do: the answer is
 * memoised in the container the first time it is asked, before a test body
 * gets a chance to plant one.
 */
function ehvrPretendConfigIsCached(): void
{
    app()->instance('config_loaded_from_cache', true);
}

function ehvrEnvPath(): string
{
    return base_path('.env');
}

function ehvrWriteEnv(string $contents): void
{
    file_put_contents(ehvrEnvPath(), $contents);
}

/**
 * Assert no configured key's base64 material appears anywhere in a command's
 * output — the one leak this feature must never produce, in either render
 * mode.
 */
function ehvrAssertNoKeyLeak(string $output): void
{
    expect($output)->not->toContain(ehvrKey('primary'))
        ->and($output)->not->toContain(ehvrKey('rotated'))
        ->and($output)->not->toContain(ehvrKey('app'))
        ->and($output)->not->toContain(base64_encode(ehvrBytes('primary')))
        ->and($output)->not->toContain(base64_encode(ehvrBytes('rotated')))
        ->and($output)->not->toContain(base64_encode(ehvrBytes('app')));
}

beforeEach(function (): void {
    $this->ehvrBasePath = sys_get_temp_dir().'/sk-encryption-health-verdict-'.bin2hex(random_bytes(6));
    mkdir($this->ehvrBasePath, 0755, true);

    // Redirect base_path() into a scratch dir so writing/chmod-ing a real
    // .env here cannot touch the testbench skeleton's own file.
    app()->setBasePath($this->ehvrBasePath);

    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');
});

afterEach(function (): void {
    $path = $this->ehvrBasePath ?? null;

    if (is_string($path) && is_dir($path)) {
        $envPath = $path.'/.env';

        // Restore permissions before cleanup — a 0000 file can otherwise
        // survive an unlink on some filesystems/CI runners.
        if (file_exists($envPath)) {
            @chmod($envPath, 0644);
            @unlink($envPath);
        }

        @rmdir($path);
    }

    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');
});

/*
|--------------------------------------------------------------------------
| 1. Cached config, chain diverges from a REAL .env file → incomplete
|--------------------------------------------------------------------------
*/

it('is downgraded to incomplete, exits non-zero, and never reports safe-to-clear when a cached config diverges from a real .env file', function (): void {
    ehvrCreateTables();
    ehvrConfigureKeys(primarySeed: 'primary');

    // The environment file names a DIFFERENT primary key than the one the
    // cached config() resolved — exactly the state a deploy that ships a
    // stale cache produces.
    ehvrWriteEnv(
        DataEncrypterFactory::PRIMARY_ENV_KEY.'='.ehvrKey('rotated')."\n"
        .DataEncrypterFactory::APP_ENV_KEY.'='.ehvrKey('app')."\n"
    );

    ehvrInsertClean();
    ehvrPretendConfigIsCached();

    $decoded = ehvrJson();

    expect($decoded['config_block']['configuration_cached'])->toBeTrue()
        ->and($decoded['config_block']['env_chain_diverges'])->toBeTrue()
        ->and($decoded['summary']['env_chain_diverged'])->toBeTrue()
        ->and($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_INCOMPLETE)
        ->and($decoded['safe_to_clear'])->toBeFalse()
        ->and($decoded['exit_code'])->not->toBe(0)
        ->and($decoded['exit_code'])->toBe(1);

    $text = ehvrHealth()['output'];

    expect($text)->not->toContain('Safe to clear')
        ->and($text)->toContain('config:clear');

    ehvrAssertNoKeyLeak($text);
    ehvrAssertNoKeyLeak((string) json_encode($decoded));
});

/**
 * Rows that read cleanly with the primary key, so a divergence downgrade (and
 * not a row-level finding) is what is under test.
 */
function ehvrInsertClean(): void
{
    DB::table('settings')->insert([
        'group' => 'mail',
        'key' => 'password',
        'value' => ehvrEncrypter('primary')->encryptString('mail-secret'),
        'encrypted' => 1,
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Cached config, chain agrees with a REAL .env file → verdict unchanged
|--------------------------------------------------------------------------
*/

it('keeps the pre-existing verdict and exit code when a cached config agrees with a real .env file', function (): void {
    ehvrCreateTables();
    ehvrConfigureKeys(primarySeed: 'primary');

    // The file names EXACTLY the chain config() already resolved.
    ehvrWriteEnv(
        DataEncrypterFactory::PRIMARY_ENV_KEY.'='.ehvrKey('primary')."\n"
        .DataEncrypterFactory::APP_ENV_KEY.'='.ehvrKey('app')."\n"
    );

    ehvrInsertClean();
    ehvrPretendConfigIsCached();

    $decoded = ehvrJson();

    expect($decoded['config_block']['configuration_cached'])->toBeTrue()
        ->and($decoded['config_block']['env_chain_diverges'])->toBeFalse()
        ->and($decoded['summary']['env_chain_diverged'])->toBeFalse()
        ->and($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_SAFE)
        ->and($decoded['safe_to_clear'])->toBeTrue()
        ->and($decoded['exit_code'])->toBe(0);

    $text = ehvrHealth()['output'];

    expect($text)->toContain('Safe to clear')
        ->and($text)->not->toContain('config:clear');

    ehvrAssertNoKeyLeak($text);
});

/*
|--------------------------------------------------------------------------
| 3. Configuration NOT cached — unchanged behaviour (no regression for the
|    ordinary, by-far-most-common case)
|--------------------------------------------------------------------------
*/

it('behaves exactly as before the fail-closed change when the configuration is not cached', function (): void {
    ehvrCreateTables();
    ehvrConfigureKeys(primarySeed: 'primary');

    // A file that would, if read, look like a divergence — proving the new
    // signal is inert without a cached config, not merely untested.
    ehvrWriteEnv(DataEncrypterFactory::PRIMARY_ENV_KEY.'='.ehvrKey('rotated')."\n");

    ehvrInsertClean();

    // No ehvrPretendConfigIsCached() call: configurationIsCached() reads its
    // real (uncached) state.

    $decoded = ehvrJson();

    expect($decoded['config_block']['configuration_cached'])->toBeFalse()
        ->and($decoded['config_block']['env_chain_diverges'])->toBeNull()
        ->and($decoded['summary']['env_chain_diverged'])->toBeFalse()
        ->and($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_SAFE)
        ->and($decoded['safe_to_clear'])->toBeTrue()
        ->and($decoded['exit_code'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 4. Cached config, .env exists but cannot be READ → fails closed
|--------------------------------------------------------------------------
|
| The one branch neither this file's own sibling tests nor
| EncrypterCoverageTest.php exercise: EncrypterCoverage::environmentFileContents()
| returns null (not '') when the file EXISTS and is_readable() is false, and
| envChainDiverges() treats that identically to a real divergence — "could not
| check" must never resolve to "safe". A missing $_SERVER var (what the sibling
| suite plants) instead resolves to an empty chain via a completely different
| branch (environmentChainMaterial() returning []), so it never reaches this
| code path.
|
*/

it('is downgraded, not safe-to-clear, when configuration is cached and the .env file exists but cannot be read', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Running as root: file permissions cannot block a read, so this branch cannot be exercised.');
    }

    ehvrCreateTables();
    ehvrConfigureKeys(primarySeed: 'primary');

    ehvrWriteEnv(DataEncrypterFactory::PRIMARY_ENV_KEY.'='.ehvrKey('primary')."\n");
    chmod(ehvrEnvPath(), 0000);

    expect(is_readable(ehvrEnvPath()))->toBeFalse();

    ehvrInsertClean();
    ehvrPretendConfigIsCached();

    $decoded = ehvrJson();

    expect($decoded['config_block']['configuration_cached'])->toBeTrue()
        ->and($decoded['config_block']['env_chain_diverges'])->toBeTrue()
        ->and($decoded['summary']['env_chain_diverged'])->toBeTrue()
        ->and($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_INCOMPLETE)
        ->and($decoded['safe_to_clear'])->toBeFalse()
        ->and($decoded['exit_code'])->toBe(1);

    $text = ehvrHealth()['output'];

    expect($text)->not->toContain('Safe to clear')
        ->and($text)->toContain('config:clear');

    ehvrAssertNoKeyLeak($text);
    ehvrAssertNoKeyLeak((string) json_encode($decoded));

    // Restore before afterEach's cleanup runs.
    chmod(ehvrEnvPath(), 0644);
});
