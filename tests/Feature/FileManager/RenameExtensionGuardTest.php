<?php

/*
|--------------------------------------------------------------------------
| FileManager — rename uzantı guard testleri (P1 T7 bulgusu)
|--------------------------------------------------------------------------
|
| Upload guard'ı (mimetypes + extensions + segment yasak listesi) yalnız
| yükleme anında çalışır; media library `file_name` değişince dosyayı
| fiziksel olarak yeniden adlandırır. Rename serbest bırakılırsa doğrulanmış
| bir GIF `.html`/`.svg`/`.php` adıyla public diskten aktif içerik olarak
| servis edilir. RenameFileRequest::withValidator() bunu iki kuralla kapatır:
|
|   A) Yeni ad mevcut uzantıyı korumak zorunda (büyük/küçük harf duyarsız).
|   B) Yeni adın hiçbir nokta segmenti yasak listesinde olamaz (x.php.pdf).
|   C) Uzantısız ad reddedilir (tarayıcı sniff'ine bırakılmaz).
|   D) Uzantıyı koruyan meşru rename çalışmaya devam eder (pozitif kontrol).
|
| Context: ShareLinkTest ile aynı desen — TestOwner'a bağlı tek bir test
| context'i container'daki ContextRegistry'ye kaydedilir; authorize closure
| yalnız 'update' ability'sine izin verir.
|
*/

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Domain\FileManager\Support\ContextRegistry;
use Lvntr\StarterKit\Tests\Stubs\TestMedia;
use Lvntr\StarterKit\Tests\Stubs\TestOwner;

const RENAME_GUARD_OWNER_ID = 'rename-guard-owner';

function bindRenameGuardRegistry(): void
{
    $registry = new ContextRegistry;
    $owner = (new TestOwner)->forceFill(['id' => RENAME_GUARD_OWNER_ID]);

    $registry->register('rename_ctx', [
        'model' => TestOwner::class,
        'path' => 'rename-guard/files',
        'resolve' => fn (?string $id) => $owner,
        'authorize' => fn ($actor, string $ability, $owner) => $ability === 'update',
    ]);

    app()->instance(ContextRegistry::class, $registry);
}

function renameGuardMedia(string $fileName = 'document.pdf'): TestMedia
{
    $id = DB::table('media')->insertGetId([
        'model_type' => TestOwner::class,
        'model_id' => RENAME_GUARD_OWNER_ID,
        'uuid' => Str::uuid()->toString(),
        'collection_name' => 'files',
        'name' => pathinfo($fileName, PATHINFO_FILENAME),
        'file_name' => $fileName,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'conversions_disk' => null,
        'size' => 1024,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => null,
        'folder_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]);

    return TestMedia::query()->findOrFail($id);
}

function renameGuardActor(): Authenticatable
{
    $actor = new class extends Model implements Authenticatable, AuthorizableContract
    {
        use AuthenticatableTrait, AuthorizableTrait;

        protected $table = 'users';

        protected $guarded = [];

        public $timestamps = false;

        public $incrementing = false;

        protected $keyType = 'string';
    };

    return $actor->forceFill(['id' => 'rename-guard-actor']);
}

function renameGuardRequest(TestMedia $media, string|array $newName)
{
    return test()->actingAs(renameGuardActor())
        ->patchJson(route('file-manager.files.rename', ['media' => $media->getKey()]), [
            'name' => $newName,
            'context' => 'rename_ctx',
        ]);
}

beforeEach(function (): void {
    bindRenameGuardRegistry();
});

it('rejects a rename that changes the extension to active content', function (string $newName): void {
    $media = renameGuardMedia();

    $response = renameGuardRequest($media, $newName);

    $response->assertStatus(422);
    expect($response->json('errors.name'))->not->toBeNull()
        ->and($response->json('errors.context'))->toBeNull()
        ->and(TestMedia::query()->findOrFail($media->getKey())->file_name)->toBe('document.pdf');
})->with(['document.html', 'document.svg', 'document.php', 'document.HTML']);

it('rejects a rename that keeps the extension but adds a blocked segment', function (): void {
    $media = renameGuardMedia();

    $response = renameGuardRequest($media, 'document.php.pdf');

    $response->assertStatus(422);
    expect($response->json('errors.name'))->not->toBeNull()
        ->and(TestMedia::query()->findOrFail($media->getKey())->file_name)->toBe('document.pdf');
});

it('rejects a rename that drops the extension', function (): void {
    $media = renameGuardMedia();

    $response = renameGuardRequest($media, 'document');

    $response->assertStatus(422);
    expect($response->json('errors.name'))->not->toBeNull();
});

it('leaves a non-string name to the string rule instead of faulting', function (): void {
    $media = renameGuardMedia();

    $response = renameGuardRequest($media, ['invalid']);

    $response->assertStatus(422);
    expect($response->json('errors.name'))->not->toBeNull();
});

it('still allows a rename that keeps the extension', function (): void {
    Storage::fake('public');

    $media = renameGuardMedia();
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'pdf-bytes');

    renameGuardRequest($media, 'renamed.PDF')->assertOk();

    $fresh = TestMedia::query()->findOrFail($media->getKey());

    expect($fresh->file_name)->toBe('renamed.PDF');
    Storage::disk('public')->assertExists($fresh->getPathRelativeToRoot());
});
