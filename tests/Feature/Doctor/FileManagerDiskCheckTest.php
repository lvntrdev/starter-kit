<?php

/*
|--------------------------------------------------------------------------
| FileManagerDiskCheck Testleri
|--------------------------------------------------------------------------
|
| sk:doctor artık FileManager'ın gerçekten yazdığı diski (media-library.disk_name)
| denetler: tanımsız disk fail, kayıp kök dizin warn üretir. Sonuç mesajı
| çözülen disk adını taşımalı — filesystems.default'a değil.
*/

use Lvntr\StarterKit\Console\Doctor\Checks\FileManagerDiskCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorStatus;

test('media-library.disk_name tanımlı ve dizin yazılabilirken ok döner ve gerçek disk adını taşır', function () {
    // filesystems.default kasıtlı farklı bir disk'e işaret eder — check bunu
    // değil, media-library.disk_name'i kullanmalı.
    config()->set('filesystems.default', 'public');

    $root = sys_get_temp_dir().'/sk_doctor_filemanager_disk_'.uniqid();
    mkdir($root, 0755, true);

    config()->set('media-library.disk_name', 'sk_test');
    config()->set('filesystems.disks.sk_test', [
        'driver' => 'local',
        'root' => $root,
    ]);

    $report = (new FileManagerDiskCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Ok)
        ->and($report->message)->toContain('sk_test')
        ->and($report->message)->not->toContain('"public"');

    rmdir($root);
});

test('media-library.disk_name tanımsız bir disk gösteriyorsa fail döner', function () {
    // filesystems.disks.sk_ghost_disk hiç tanımlı değil.
    config()->set('media-library.disk_name', 'sk_ghost_disk');

    $report = (new FileManagerDiskCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Fail)
        ->and($report->message)->toContain('sk_ghost_disk')
        ->and($report->hint)->toContain('FILESYSTEM_DISK');
});

test('local disk kökü mevcut değilse warn döner', function () {
    $missingRoot = sys_get_temp_dir().'/sk_doctor_filemanager_missing_'.uniqid();

    config()->set('media-library.disk_name', 'sk_test_missing');
    config()->set('filesystems.disks.sk_test_missing', [
        'driver' => 'local',
        'root' => $missingRoot,
    ]);

    $report = (new FileManagerDiskCheck)->run();

    expect($report->status)->toBe(DoctorStatus::Warn)
        ->and($report->message)->toContain('sk_test_missing')
        ->and($report->message)->toContain('root directory not found')
        ->and($report->hint)->toContain('storage:link');
});
