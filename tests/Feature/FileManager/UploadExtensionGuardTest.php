<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Lvntr\StarterKit\Http\Requests\FileManager\UploadFileRequest;

/*
|--------------------------------------------------------------------------
| FileManager — upload uzantı guard regresyon testleri (P1 T2)
|--------------------------------------------------------------------------
|
| İki katmanlı savunmayı pinler:
|
|   A/B/C) UploadFileRequest::rules() — `mimetypes:` gerçek içerik sniff'i,
|          `extensions:` client uzantısı. Gerçek GIF byte'ları taşıyan bir
|          dosya yanlış (.html) client uzantısıyla geldiğinde SADECE
|          `extensions:` reddeder; `mimetypes:` içerik gerçekten GIF olduğu
|          için geçer. `extensions:` kuralı kaldırılırsa Case A kırmızı yanar.
|   D)     StarterKitServiceProvider::applyVendorConfigDefaults() —
|          media-library.disallowed_extensions provider boot'undan sonra hem
|          kit'in eklediği aktif-içerik uzantılarıyla hem de Spatie'nin
|          korunan varsayılanlarıyla dolu olmalı. Merge kaldırılırsa kırmızı
|          yanar.
|   E)     stubs/app/Http/Requests/UploadAvatarRequest.php — avatar upload
|          kuralının `extensions:` katmanını taşıdığı stub kaynağından pinlenir.
|
| Standalone `Validator::make()` kullanılır (AppearanceUploadValidationTest
| ile aynı desen): `rules()` `contextRules()` üzerinden 'context' alanını
| 'required' yapar; bu alan burada verilmediğinden `$validator->passes()`/
| `fails()` HER ZAMAN false döner (context eksikliğinden). Bu yüzden sadece
| 'files.0' alanına özel hata var mı yok mu kontrol edilir.
|
*/

beforeEach(function (): void {
    config(['file-manager.settings.accepted_mimes' => ['image/gif', 'application/pdf']]);
});

/**
 * Minimal geçerli GIF89a header + 1x1 opak frame. Gerçek dosya içeriği olarak
 * finfo/Symfony mime sniffer'ı tarafından `image/gif` olarak tanınır — bkz.
 * `->mimeType('image/gif')` override'ı: `Illuminate\Http\Testing\File`
 * `getMimeType()`'ı varsayılan olarak dosya adının uzantısından tahmin eder
 * (gerçek içerik sniffing yapmaz), bu yüzden "içerik gerçekten GIF" senaryosu
 * production'daki finfo davranışını taklit etmek için açıkça set edilir.
 */
function uploadGuardGifBytes(): string
{
    return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
}

/**
 * Minimal geçerli PDF header/trailer — gerçek dosya içeriği olarak
 * `application/pdf` olarak tanınır.
 */
function uploadGuardPdfBytes(): string
{
    return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
}

// ──────────────────────────────────────────────────────────────────────────────
// A/B/C. UploadFileRequest::rules() — mimetypes: (içerik) vs extensions: (client uzantısı)
// ──────────────────────────────────────────────────────────────────────────────

it('rejects real GIF bytes served under an .html client extension (extensions: catches what mimetypes: misses)', function (): void {
    $file = UploadedFile::fake()
        ->createWithContent('rapor.html', uploadGuardGifBytes())
        ->mimeType('image/gif');

    $validator = Validator::make(['files' => [$file]], (new UploadFileRequest)->rules());

    expect($validator->errors()->has('files.0'))->toBeTrue();
});

it('accepts the same GIF bytes under the matching .gif client extension', function (): void {
    $file = UploadedFile::fake()
        ->createWithContent('rapor.gif', uploadGuardGifBytes())
        ->mimeType('image/gif');

    $validator = Validator::make(['files' => [$file]], (new UploadFileRequest)->rules());

    expect($validator->errors()->has('files.0'))->toBeFalse();
});

it('accepts real PDF bytes under the matching .pdf client extension', function (): void {
    $file = UploadedFile::fake()
        ->createWithContent('rapor.pdf', uploadGuardPdfBytes())
        ->mimeType('application/pdf');

    $validator = Validator::make(['files' => [$file]], (new UploadFileRequest)->rules());

    expect($validator->errors()->has('files.0'))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// D. StarterKitServiceProvider — media-library.disallowed_extensions merge
// ──────────────────────────────────────────────────────────────────────────────

it("merges the kit's active-content extensions into media-library.disallowed_extensions after boot", function (): void {
    $disallowed = config('media-library.disallowed_extensions');

    // Kit'in provider boot'unda eklediği aktif-içerik uzantıları.
    expect($disallowed)->toContain('php', 'html', 'svg', 'js', 'xml');

    // Spatie'nin kendi varsayılanları merge sırasında ezilmemiş olmalı.
    expect($disallowed)->toContain('phar', 'htaccess');
});

// ──────────────────────────────────────────────────────────────────────────────
// E. Avatar stub — extensions: kuralının stub kaynağında pinlenmesi
// ──────────────────────────────────────────────────────────────────────────────

it('pins the extensions: rule in the UploadAvatarRequest stub source', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/app/Http/Requests/UploadAvatarRequest.php');

    expect($source)->toContain('extensions:jpg,jpeg,png,webp');
});

// ──────────────────────────────────────────────────────────────────────────────
// F. withValidator() — orta segmentteki yasaklı uzantı (shell.php.gif)
// ──────────────────────────────────────────────────────────────────────────────

it('rejects a blocked extension in a middle segment of the client name (case F)', function (): void {
    $file = UploadedFile::fake()->createWithContent('shell.php.gif', uploadGuardGifBytes())->mimeType('image/gif');
    $request = UploadFileRequest::create('/file-manager/files', 'POST', ['context' => 'user'], [], ['files' => [$file]]);

    // Son uzantı (gif) allowlist'te: kural katmanı tek başına geçer.
    expect(Validator::make($request->all(), $request->rules())->errors()->has('files.0'))->toBeFalse();

    // after-hook (withValidator) her segmenti yasak listesine karşı kontrol eder.
    $validator = Validator::make($request->all(), $request->rules());
    (new ReflectionMethod($request, 'withValidator'))->invoke($request, $validator);

    expect($validator->errors()->has('files.0'))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// G. Haritada olmayan admin MIME'ları Symfony MIME veritabanından çözülür
// ──────────────────────────────────────────────────────────────────────────────

it('derives extensions for admin-added MIME types outside the kit map (case G)', function (): void {
    config(['file-manager.settings.accepted_mimes' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/x-rar-compressed',
        'text/markdown',
    ]]);

    $rules = (new UploadFileRequest)->rules();
    $extensionsRule = collect($rules['files.*'])->first(fn ($rule) => is_string($rule) && str_starts_with($rule, 'extensions:'));

    expect($extensionsRule)->toContain('pptx')
        ->and($extensionsRule)->toContain('rar')
        ->and($extensionsRule)->toContain('md');
});
