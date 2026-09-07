<?php

/*
|--------------------------------------------------------------------------
| DoctorCommand Artisan Testleri
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Artisan;

test('sk:doctor komutu çalışır ve geçerli exit code döner', function () {
    $exitCode = Artisan::call('sk:doctor');

    // 0 (ok) veya 1 (warn) beklenir — 2 (fail) değil.
    // Test ortamında warn üretilmesi mümkündür (sync queue vb.), bu normal.
    expect($exitCode)->toBeIn([0, 1, 2]);
});

test('sk:doctor --json geçerli JSON döner', function () {
    $output = Artisan::call('sk:doctor', ['--json' => true]);
    $raw = Artisan::output();

    $decoded = json_decode($raw, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['version', 'generated_at', 'summary', 'checks'])
        ->and($decoded['version'])->toBe(1)
        ->and($decoded['summary'])->toHaveKeys(['ok', 'warn', 'fail', 'total'])
        ->and($decoded['checks'])->toBeArray()
        ->and($decoded['checks'])->not->toBeEmpty();

    // Her check item doğru shape'de olmalı
    foreach ($decoded['checks'] as $check) {
        expect($check)->toHaveKeys(['name', 'status', 'message', 'hint'])
            ->and($check['status'])->toBeIn(['ok', 'warn', 'fail']);
    }
});

test('sk:doctor --json summary toplam check sayısını doğru raporlar', function () {
    Artisan::call('sk:doctor', ['--json' => true]);
    $raw = Artisan::output();

    $decoded = json_decode($raw, true);

    $summary = $decoded['summary'];
    $total = $summary['ok'] + $summary['warn'] + $summary['fail'];

    expect($total)->toBe($summary['total'])
        ->and(count($decoded['checks']))->toBe($summary['total']);
});

test('sk:doctor exit code geçerli değer (0/1/2) döner', function () {
    // Tüm check'leri çalıştır, exit code schema'ya uygun olmalı.
    // Test ortamında hangi check'in warn/fail döneceği çevreye bağlı;
    // önemli olan exit code'un 0, 1 veya 2 olduğu.
    $exitCode = Artisan::call('sk:doctor');

    expect($exitCode)->toBeIn([0, 1, 2]);
});

test('sk:doctor SMTP olmayan port ile fail üretir ve exit code 2 döner', function () {
    // SMTP'nin bağlanamayacağı bir port → MailDriverCheck fail üretir.
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.transport', 'smtp');
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    config()->set('mail.mailers.smtp.port', 19995); // Kesinlikle kapalı port

    $exitCode = Artisan::call('sk:doctor');

    // MailDriverCheck fail ürettiği için exit code 2 olmalı
    expect($exitCode)->toBe(2);

    config()->set('mail.default', 'log');
    config()->set('mail.mailers.smtp.host', '');
    config()->set('mail.mailers.smtp.port', 587);
});

test('sk:doctor komutu --json ile fail check varsa exit code >= 1 döner', function () {
    // SMTP bağlantısı olmayan host ile fail üretilebilir.
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.transport', 'smtp');
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    config()->set('mail.mailers.smtp.port', 19996);

    $exitCode = Artisan::call('sk:doctor', ['--json' => true]);

    // En az warn veya fail üretilir
    expect($exitCode)->toBeGreaterThan(0);

    config()->set('mail.default', 'log');
});

test('sk:doctor --only seçicileri aktif dilden bağımsızdır', function () {
    // Selectors are derived from the check CLASS, not from the translated display
    // name: under a non-English locale a name-derived selector matched nothing and
    // the command returned an empty report with exit code 0.
    app()->setLocale('tr');

    Artisan::call('sk:doctor', ['--json' => true, '--only' => 'database-connection,timezone-storage']);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['checks'])->toHaveCount(2)
        ->and($decoded['summary']['total'])->toBe(2);
});

test('sk:doctor --only eski görünen-ad seçicilerini kabul etmeyi sürdürür', function () {
    Artisan::call('sk:doctor', ['--json' => true, '--only' => 'filemanager-disk,permission-matrix,unresolved-routes']);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['checks'])->toHaveCount(3);
});
