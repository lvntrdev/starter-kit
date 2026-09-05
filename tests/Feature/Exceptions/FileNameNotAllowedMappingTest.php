<?php

/*
|--------------------------------------------------------------------------
| ApiExceptionHandler — FileNameNotAllowed → 422 mapleme (P1 T2)
|--------------------------------------------------------------------------
|
| Çift uzantılı bir yükleme (shell.php.jpg) request validator'ının "son
| uzantı" kontrolünü geçer ama media library'nin segment-bazlı yasak-uzantı
| koruması tarafından reddedilir ve FileNameNotAllowed fırlatılır. Bu mapleme
| eklenmeden önce istisna genel 500 dalına düşüyordu; artık 422 + kürate
| edilmiş mesajla döner ve Spatie'nin ham mesajı (orijinal + sanitize edilmiş
| dosya adlarını taşıyan) sızdırılmaz.
|
*/

use Lvntr\StarterKit\Exceptions\ApiExceptionHandler;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;

/**
 * Private `resolve()` maplemesini reflection ile çağırır — saf [status, message]
 * eşlemesini test eder (Request / app boot gerektirmez).
 *
 * @return array{0: int, 1: string}
 */
function resolveFileNameNotAllowed(Throwable $e): array
{
    $method = new ReflectionMethod(ApiExceptionHandler::class, 'resolve');

    /** @var array{0: int, 1: string} $result */
    $result = $method->invoke(null, $e);

    return $result;
}

it('maps FileNameNotAllowed (double-extension upload) to 422 without leaking the raw file names', function (): void {
    $exception = FileNameNotAllowed::create('shell.php.jpg', 'shell-php.jpg', 'php');

    [$status, $message] = resolveFileNameNotAllowed($exception);

    expect($status)->toBe(422);
    expect($message)->toBe('The uploaded file name is not allowed.');

    // Spatie'nin ham mesajı hem orijinal hem sanitize edilmiş dosya adını
    // taşır ("shell.php.jpg" / "shell-php.jpg") — kürate edilmiş mesaj bunu
    // sızdırmamalı.
    expect($message)->not->toContain('shell');
});

it('maps FileIsTooBig to 422 with a constant message that hides the path', function (): void {
    config(['media-library.max_file_size' => 1024]);

    [$status, $message] = resolveFileNameNotAllowed(FileIsTooBig::create('/srv/uploads/large.bin', 4096));

    expect($status)->toBe(422)
        ->and($message)->toBe('The uploaded file is too large.')
        ->and($message)->not->toContain('large.bin');
});
