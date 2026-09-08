<?php

/*
|--------------------------------------------------------------------------
| Task 17 — Yeni Doctor Check'leri + Timeout Guard Testleri
|--------------------------------------------------------------------------
| NodeVersionCheck, QueueWorkerCheck, ScheduleConfiguredCheck (warn/stale)
| ve DoctorCommand::runGuarded timeout/hata koruması.
*/

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Commands\DoctorCommand;
use Lvntr\StarterKit\Console\Doctor\Checks\DataEncryptionKeyCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\LogStackCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\MissingKitDependenciesCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\NodeVersionCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\QueueWorkerCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\ScheduleConfiguredCheck;
use Lvntr\StarterKit\Console\Doctor\Checks\TimezoneStorageCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\Console\Doctor\DoctorStatus;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

/*
| NodeVersionCheck
*/

test('NodeVersionCheck geçerli bir rapor döner', function () {
    $report = (new NodeVersionCheck)->run();

    expect($report)->toBeInstanceOf(DoctorReport::class)
        ->and($report->name)->toBe('Node Version')
        // Node kurulu (ok) ya da eksik/eski (warn) — fail üretmez.
        ->and($report->status)->toBeIn([DoctorStatus::Ok, DoctorStatus::Warn]);
});

test('NodeVersionCheck Node kuruluysa sürümü raporlar', function () {
    $report = (new NodeVersionCheck)->run();

    // Bu ortamda node mevcut → ok ve sürüm mesajda yer alır.
    if ($report->isOk()) {
        expect($report->message)->toContain('Node.js')
            ->and($report->message)->toContain('minimum requirement');
    } else {
        // node yoksa: warn + kurulum hint'i
        expect($report->hint)->toContain('Node.js');
    }
});

/*
| QueueWorkerCheck
*/

test('QueueWorkerCheck sync driver için OK döner (worker gerekmez)', function () {
    config()->set('queue.default', 'sync');

    $report = (new QueueWorkerCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('sync');
});

test('QueueWorkerCheck async (redis) driver için warn döner', function () {
    config()->set('queue.default', 'redis');

    $report = (new QueueWorkerCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain('async')
        ->and($report->hint)->toContain('queue:work');

    config()->set('queue.default', 'sync');
});

test('QueueWorkerCheck database driver jobs tablosu yoksa warn döner', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', config('database.default'));
    config()->set('queue.connections.database.table', 'jobs_missing_table');

    $report = (new QueueWorkerCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain('does not exist')
        ->and($report->hint)->toContain('queue:table');

    config()->set('queue.default', 'sync');
});

/*
| ScheduleConfiguredCheck — warn/stale davranışı
*/

function skScheduleLastRunPath(): string
{
    return storage_path('framework/.schedule-last-run');
}

function skClearScheduleLastRun(): void
{
    $path = skScheduleLastRunPath();

    if (file_exists($path)) {
        @unlink($path);
    }

    @mkdir(dirname($path), 0777, true);
}

function skDefineOneScheduledTask(): void
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $schedule->call(static fn () => null);
}

test('ScheduleConfiguredCheck görev yoksa warn döner', function () {
    // Varsayılan: schedule boş.
    $report = (new ScheduleConfiguredCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain('No scheduled tasks');
});

test('ScheduleConfiguredCheck görev var ama heartbeat dosyası yoksa cron uyarısı verir', function () {
    skClearScheduleLastRun();
    skDefineOneScheduledTask();

    $report = (new ScheduleConfiguredCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain('never been recorded')
        ->and($report->hint)->toContain('schedule:run');
});

test('ScheduleConfiguredCheck heartbeat tazeyse OK döner', function () {
    skClearScheduleLastRun();
    skDefineOneScheduledTask();
    file_put_contents(skScheduleLastRunPath(), (string) time());

    $report = (new ScheduleConfiguredCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('Last run:');

    skClearScheduleLastRun();
});

test('ScheduleConfiguredCheck heartbeat bayatsa stale uyarısı verir', function () {
    skClearScheduleLastRun();
    skDefineOneScheduledTask();
    // 10 dakika önce → 300sn eşiğini aşar.
    file_put_contents(skScheduleLastRunPath(), (string) (time() - 600));

    $report = (new ScheduleConfiguredCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain('last schedule:run was');

    skClearScheduleLastRun();
});

/*
| TimezoneStorageCheck
*/

test('TimezoneStorageCheck UTC application ve MySQL session timezone için OK döner', function () {
    config()->set('app.timezone', 'UTC');

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT @@session.time_zone AS time_zone')
        ->andReturn((object) ['time_zone' => '+00:00']);
    DB::shouldReceive('connection')->once()->andReturn($connection);

    $report = (new TimezoneStorageCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('Application timezone is UTC')
        ->and($report->message)->toContain('+00:00');
});

test('TimezoneStorageCheck UTC dışı storage timezone için fail döner', function () {
    config()->set('app.timezone', 'Europe/Istanbul');

    $report = (new TimezoneStorageCheck)->run();

    expect($report->isFail())->toBeTrue()
        ->and($report->message)->toContain('Europe/Istanbul')
        ->and($report->message)->toContain('ambiguous')
        ->and($report->hint)->toContain('APP_TIMEZONE=UTC');

    config()->set('app.timezone', 'UTC');
});

test('TimezoneStorageCheck MySQL SYSTEM session timezone için fail döner', function () {
    config()->set('app.timezone', 'UTC');

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT @@session.time_zone AS time_zone')
        ->andReturn((object) ['time_zone' => 'SYSTEM']);
    DB::shouldReceive('connection')->once()->andReturn($connection);

    $report = (new TimezoneStorageCheck)->run();

    expect($report->isFail())->toBeTrue()
        ->and($report->message)->toContain('SYSTEM')
        ->and($report->message)->toContain('TIMESTAMP')
        ->and($report->message)->toContain('rows on disk')
        ->and($report->hint)->toContain('config/database.php')
        ->and($report->hint)->toContain("'timezone' => '+00:00'");
});

test('TimezoneStorageCheck MySQL dışı driver için session kontrolünü uygulanamaz raporlar', function () {
    config()->set('app.timezone', 'UTC');

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
    $connection->shouldNotReceive('selectOne');
    DB::shouldReceive('connection')->once()->andReturn($connection);

    $report = (new TimezoneStorageCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('does not apply')
        ->and($report->message)->toContain('sqlite');
});

test('TimezoneStorageCheck bağlantı hatasında warn döner ve kontrolü başarılı saymaz', function () {
    config()->set('app.timezone', 'UTC');

    DB::shouldReceive('connection')
        ->once()
        ->andThrow(new RuntimeException('connection refused'));

    $report = (new TimezoneStorageCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->isOk())->toBeFalse()
        ->and($report->message)->toContain('connection refused')
        ->and($report->message)->toContain('Could not verify');
});

test('TimezoneStorageCheck --only seçicisiyle tek başına çalışır', function () {
    config()->set('app.timezone', 'UTC');

    Artisan::call('sk:doctor', ['--json' => true, '--only' => 'timezone-storage']);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['checks'])->toHaveCount(1)
        ->and($decoded['checks'][0]['name'])->toBe('Timezone Storage')
        ->and($decoded['checks'][0]['status'])->toBe('ok');
});

/*
| DoctorCommand::runGuarded — hata/timeout koruması
*/

test('runGuarded fırlatan bir check için warn üretir, exception sızdırmaz', function () {
    $throwing = new class implements DoctorCheck
    {
        public function name(): string
        {
            return 'Boom Check';
        }

        public function run(): DoctorReport
        {
            throw new RuntimeException('kaboom');
        }
    };

    $command = new DoctorCommand;
    $method = new ReflectionMethod($command, 'runGuarded');

    /** @var DoctorReport $report */
    $report = $method->invoke($command, $throwing);

    expect($report)->toBeInstanceOf(DoctorReport::class)
        ->and($report->isWarn())->toBeTrue()
        ->and($report->name)->toBe('Boom Check')
        ->and($report->message)->toContain('unexpected error');
});

test('runGuarded normal bir check sonucunu değiştirmeden döner', function () {
    $okCheck = new class implements DoctorCheck
    {
        public function name(): string
        {
            return 'Fast Check';
        }

        public function run(): DoctorReport
        {
            return DoctorReport::ok('Fast Check', 'all good');
        }
    };

    $command = new DoctorCommand;
    $method = new ReflectionMethod($command, 'runGuarded');

    /** @var DoctorReport $report */
    $report = $method->invoke($command, $okCheck);

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toBe('all good');
});

/*
| LogStackCheck — yalnizca AKTIF kanala bakar.
|
| Regresyon: check eskiden logging.default degerine bakmadan logging.channels.stack
| icindeki "single" degerini okuyordu; LOG_CHANNEL=daily olan dogru yapilandirilmis
| bir uygulamada bile uyari uretiyordu.
*/

test('LogStackCheck aktif kanal stack ve icinde single varsa uyarir', function () {
    config()->set('logging.default', 'stack');
    config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single']]);
    config()->set('logging.channels.single', ['driver' => 'single']);

    $report = (new LogStackCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Warn)
        ->and($report->message)->toContain('single')
        ->and($report->hint)->toContain('LOG_STACK=daily');
});

test('LogStackCheck aktif kanal daily iken stack icindeki single icin uyarmaz', function () {
    config()->set('logging.default', 'daily');
    config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single']]);
    config()->set('logging.channels.single', ['driver' => 'single']);
    config()->set('logging.channels.daily', ['driver' => 'daily']);

    $report = (new LogStackCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('daily');
});

test('LogStackCheck aktif kanal dogrudan single ise uyarir ve LOG_CHANNEL onerir', function () {
    config()->set('logging.default', 'single');
    config()->set('logging.channels.single', ['driver' => 'single']);

    $report = (new LogStackCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Warn)
        ->and($report->hint)->toContain('LOG_CHANNEL=daily');
});

test('LogStackCheck stack icindeki tum kanallar rotasyonluysa OK doner', function () {
    config()->set('logging.default', 'stack');
    config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['daily']]);
    config()->set('logging.channels.daily', ['driver' => 'daily']);

    $report = (new LogStackCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('daily');
});

/*
| LogManager::createStackDriver() uyumu — string channels, ic ice stack, dongu.
*/

test('LogStackCheck virgullu string channels degerini ayristirir', function () {
    // Laravel is_string($config['channels']) durumunda explode(',') yapar;
    // (array) cast tek elemanli "single,daily" dizisi uretip kacirilmasina yol acardi.
    config()->set('logging.default', 'stack');
    config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => 'single,daily']);
    config()->set('logging.channels.single', ['driver' => 'single']);
    config()->set('logging.channels.daily', ['driver' => 'daily']);

    $report = (new LogStackCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Warn)
        ->and($report->message)->toContain('single')
        ->and($report->hint)->toContain('LOG_STACK=daily');
});

test('LogStackCheck ic ice stack icindeki single kanali bulur', function () {
    config()->set('logging.default', 'outer');
    config()->set('logging.channels.outer', ['driver' => 'stack', 'channels' => ['inner']]);
    config()->set('logging.channels.inner', ['driver' => 'stack', 'channels' => ['single']]);
    config()->set('logging.channels.single', ['driver' => 'single']);

    $report = (new LogStackCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Warn)
        ->and($report->message)->toContain('single')
        // LOG_STACK yalnizca logging.channels.stack.channels degerini besler;
        // aktif kanal baska adli bir stack ise ipucu kendi config anahtarini gosterir.
        ->and($report->hint)->toContain('logging.channels.outer.channels')
        ->and($report->hint)->not->toContain('LOG_STACK=daily');
});

test('LogStackCheck dongusel stack yapilandirmasinda sonsuz ozyinelemeye girmez', function () {
    config()->set('logging.default', 'loop');
    config()->set('logging.channels.loop', ['driver' => 'stack', 'channels' => ['loop', 'daily']]);
    config()->set('logging.channels.daily', ['driver' => 'daily']);

    $report = (new LogStackCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('daily');
});

test('LogStackCheck kanali olmayan stack icin OK doner ve mesaji bozulmaz', function () {
    config()->set('logging.default', 'empty');
    config()->set('logging.channels.empty', ['driver' => 'stack', 'channels' => []]);

    $report = (new LogStackCheck)->run();

    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('no channel resolved');
});

/*
| DataEncryptionKeyCheck — is the data-at-rest encryption hostage to APP_KEY?
|
| Three states, and the direction of each one matters more than its wording:
|
|   warn — no DATA_ENCRYPTION_KEY. This is the kit's HISTORICAL DEFAULT, so it
|          must never be a fail: a red sk:doctor on a perfectly working app
|          trains operators to ignore the whole report.
|   warn — a dedicated key IS in use but DATA_ENCRYPTION_PREVIOUS_KEYS still
|          holds something: the rotation is unfinished and the old key still has
|          to travel with the app.
|   ok   — a dedicated key with an empty previous list; key:generate cannot
|          reach the encrypted settings or the 2FA secrets.
|
| Plus the fail path: a chain that does not resolve at all, where every
| encrypted read is ALREADY throwing.
|
| The check is config-only by design — it must not touch a table or decrypt
| anything, so these tests never build one.
*/

function skDekKey(string $seed): string
{
    return 'base64:'.base64_encode(substr(hash('sha256', $seed, true), 0, 32));
}

/**
 * @param  string|array<int, string>|null  $previousKeys
 */
function skDekConfigure(?string $primary, string|array|null $previousKeys = null, ?string $appKey = 'app'): void
{
    config()->set('app.key', $appKey === null ? null : skDekKey($appKey));
    config()->set('app.cipher', 'AES-256-CBC');
    config()->set('app.previous_keys', []);
    config()->set('starter-kit.encryption.key', $primary === null ? null : skDekKey($primary));
    config()->set('starter-kit.encryption.previous_keys', $previousKeys);
    config()->set('starter-kit.encryption.cipher', null);

    // The check flushes the memoised chain itself; this keeps a factory built
    // earlier in the same process from disagreeing about the config.
    app(DataEncrypterFactory::class)->flush();
}

test('DataEncryptionKeyCheck dedicated key yokken warn döner ve fail üretmez', function () {
    skDekConfigure(primary: null);

    $report = (new DataEncryptionKeyCheck)->run();

    expect($report->name)->toBe('Data Encryption Key')
        ->and($report->isWarn())->toBeTrue()
        ->and($report->isFail())->toBeFalse()
        ->and($report->message)->toContain('No '.DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($report->message)->toContain('key:generate')
        ->and($report->message)->toContain('silent')
        ->and($report->hint)->toContain('encryption:key')
        ->and($report->hint)->toContain('server-migration-runbook');
});

test('DataEncryptionKeyCheck dedicated key varken bekleyen previous key için warn döner', function () {
    skDekConfigure(primary: 'primary', previousKeys: skDekKey('retired'));

    $report = (new DataEncryptionKeyCheck)->run();

    expect($report->isWarn())->toBeTrue()
        ->and($report->message)->toContain(DataEncrypterFactory::PREVIOUS_ENV_KEY)
        ->and($report->message)->toContain('rotation is unfinished')
        ->and($report->hint)->toContain('encryption:rekey')
        ->and($report->hint)->toContain('encryption:health')
        // Never the key material itself — only the env var name.
        ->and($report->message)->not->toContain(skDekKey('retired'))
        ->and($report->hint)->not->toContain(skDekKey('retired'));
});

test('DataEncryptionKeyCheck previous key listesi dizi biçiminde verilse de warn döner', function () {
    skDekConfigure(primary: 'primary', previousKeys: [skDekKey('retired')]);

    expect((new DataEncryptionKeyCheck)->run()->isWarn())->toBeTrue();
});

test('DataEncryptionKeyCheck dedicated key + boş previous key listesi için OK döner', function (string|array|null $previousKeys) {
    skDekConfigure(primary: 'primary', previousKeys: $previousKeys);

    $report = (new DataEncryptionKeyCheck)->run();

    // The OK message must NOT claim `key:generate` is harmless: this check
    // reads config only and has never looked at a row, so a value written
    // under APP_KEY before adoption is invisible to it. It points at
    // `encryption:health`, which does read the rows, instead.
    expect($report->isOk())->toBeTrue()
        ->and($report->message)->toContain('no previous keys pending')
        ->and($report->message)->toContain('encryption:health')
        ->and($report->message)->not->toContain('cannot reach');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['   '],
    'empty array' => [[]],
    'array of blanks' => [['', '  ']],
]);

test('DataEncryptionKeyCheck çözülemeyen anahtar zinciri için fail döner', function () {
    // Both env vars empty: every encrypted read is already throwing, so this IS
    // a failure rather than a warning.
    skDekConfigure(primary: null, appKey: null);

    $report = (new DataEncryptionKeyCheck)->run();

    expect($report->isFail())->toBeTrue()
        ->and($report->message)->toContain('could not be resolved')
        ->and($report->message)->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($report->hint)->toContain('Do NOT clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY);
});

test('DataEncryptionKeyCheck bozuk bir anahtar için fail döner ve anahtar materyalini sızdırmaz', function () {
    config()->set('app.key', skDekKey('app'));
    config()->set('app.cipher', 'AES-256-CBC');
    config()->set('app.previous_keys', []);
    config()->set('starter-kit.encryption.key', 'base64:not-valid-base64!!');
    config()->set('starter-kit.encryption.previous_keys', null);
    config()->set('starter-kit.encryption.cipher', null);
    app(DataEncrypterFactory::class)->flush();

    $report = (new DataEncryptionKeyCheck)->run();

    expect($report->isFail())->toBeTrue()
        ->and($report->message)->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($report->message)->not->toContain('not-valid-base64');
});

test('DataEncryptionKeyCheck sk:doctor --only seçicisiyle tek başına çalışır', function () {
    skDekConfigure(primary: 'primary');

    Artisan::call('sk:doctor', ['--json' => true, '--only' => 'data-encryption-key']);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['checks'])->toHaveCount(1)
        ->and($decoded['checks'][0]['name'])->toBe('Data Encryption Key')
        ->and($decoded['checks'][0]['status'])->toBe('ok');
});

/*
| MissingKitDependenciesCheck — registration + OK against this repo's own,
| fully installed composer state (KitDependencies::missing() detection logic
| itself is covered by KitDependenciesTest.php; this only checks the report
| shape and doctor registry wiring).
*/

test('MissingKitDependenciesCheck bu repo kurulu haliyle OK döner', function () {
    $report = (new MissingKitDependenciesCheck)->run();

    expect($report)->toBeInstanceOf(DoctorReport::class)
        ->and($report->isOk())->toBeTrue();
});

test('MissingKitDependenciesCheck sk:doctor kayıtlı check kümesinde yer alır', function () {
    Artisan::call('sk:doctor', ['--json' => true, '--only' => 'missing-kit-dependencies']);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['checks'])->toHaveCount(1)
        ->and($decoded['checks'][0]['status'])->toBe('ok');
});
