<?php

/*
|--------------------------------------------------------------------------
| FileItemDTO — preview-route fallback, not a raw disk URL (Task 2 regression)
|--------------------------------------------------------------------------
|
| FileItemDTO::fromModel() used to fall back to Media::getUrl() whenever the
| disk has no temporary-URL support (every local/public disk) — a permanent,
| unauthenticated link that bypasses FileManagerAuthorizer, keeps working
| after a permission revoke, and after the file moves to trash. The fix routes
| that fallback through the authorized `file-manager.files.preview` route
| instead.
|
| Storage::fake() ALWAYS installs a temporary-URL callback (see
| Illuminate\Support\Facades\Storage::fake()), so the fallback branch this
| test targets is UNREACHABLE under it — a real 'local' disk is registered
| here instead (never faked), which is what makes getTemporaryUrl() throw
| the RuntimeException FileItemDTO::fromModel() catches.
|
| The assertion is on the URL SHAPE, not merely "did not throw": asserting
| only that a URL string came back would stay green even if the fallback
| reverted to Media::getUrl() — the regression this test exists to catch.
|
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileItemDTO;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Tests\Stubs\TestMedia;
use Lvntr\StarterKit\Tests\Stubs\TestOwner;

/**
 * Registers a disk with no temporary-URL support and no Storage::fake()
 * override, so Media::getTemporaryUrl() takes the real 'RuntimeException'
 * path FileItemDTO::fromModel() is built to catch.
 */
function fidfNoTemporaryUrlDisk(string $disk = 'fidf_no_temp_url'): string
{
    config(["filesystems.disks.{$disk}" => [
        'driver' => 'local',
        'root' => sys_get_temp_dir().'/'.$disk,
        'url' => '/'.$disk,
    ]]);

    return $disk;
}

function fidfInsertMedia(string $modelType, string $modelId, string $disk): TestMedia
{
    $id = DB::table('media')->insertGetId([
        'model_type' => $modelType,
        'model_id' => $modelId,
        'uuid' => Str::uuid()->toString(),
        'collection_name' => 'files',
        'name' => 'fallback-doc',
        'file_name' => 'fallback-doc.pdf',
        'mime_type' => 'application/pdf',
        'disk' => $disk,
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

it('emits the authorized preview route URL, not a raw disk URL, when the disk has no temporary-URL support', function (): void {
    $disk = fidfNoTemporaryUrlDisk();
    $ownerId = (string) Str::uuid();
    $media = fidfInsertMedia(TestOwner::class, $ownerId, $disk);

    $context = new FileManagerContextDTO(
        context: 'user',
        contextId: $ownerId,
        owner: (new TestOwner)->forceFill(['id' => $ownerId]),
        ownerType: TestOwner::class,
        ownerId: $ownerId,
    );

    $dto = FileItemDTO::fromModel($media, $context);

    // The regression this pins: a raw disk URL looks like
    // "/{disk}/{model_type}/{id}/…" (DefaultUrlGenerator::getUrl()) and never
    // contains the preview route's own path segment. Asserting containment of
    // the route path — not merely "a URL came back" — is what fails if
    // previewUrl() is ever replaced with $media->getUrl() again.
    expect($dto->url)->toContain('file-manager/files/'.$media->getKey().'/preview')
        ->and($dto->url)->not->toContain('/'.$disk.'/');
});

it('still falls back to the raw disk URL when no context is available (documented back-compat path)', function (): void {
    $disk = fidfNoTemporaryUrlDisk('fidf_no_temp_url_nullctx');
    $media = fidfInsertMedia(TestOwner::class, (string) Str::uuid(), $disk);

    // No context passed — the ONLY branch that may still hand out
    // Media::getUrl(), and only because a direct consumer call cannot supply
    // one; every kit call site threads the context through.
    $dto = FileItemDTO::fromModel($media);

    expect($dto->url)->not->toContain('file-manager/files')
        ->and($dto->url)->toContain('/'.$disk.'/');
});

// ──────────────────────────────────────────────────────────────────────────────
// publicUrl — the one link that must outlive the admin session
// ──────────────────────────────────────────────────────────────────────────────

it('exposes a permanent public_url alongside the gated url on a public disk', function (): void {
    // The editor persists <img src> into rich text that is later rendered to
    // visitors who never authenticate, so it embeds public_url. If this ever
    // returns null on a public disk, every published image silently 401s.
    $disk = fidfNoTemporaryUrlDisk('fidf_public_disk');
    config(["filesystems.disks.{$disk}.visibility" => 'public']);

    $ownerId = (string) Str::uuid();
    $media = fidfInsertMedia(TestOwner::class, $ownerId, $disk);

    $context = new FileManagerContextDTO(
        context: 'user',
        contextId: $ownerId,
        owner: (new TestOwner)->forceFill(['id' => $ownerId]),
        ownerType: TestOwner::class,
        ownerId: $ownerId,
    );

    $dto = FileItemDTO::fromModel($media, $context);

    expect($dto->publicUrl)->toContain('/'.$disk.'/')
        ->and($dto->url)->toContain('file-manager/files/'.$media->getKey().'/preview')
        ->and($dto->toArray()['public_url'])->toBe($dto->publicUrl);
});

it('leaves public_url null on a disk that is not publicly readable', function (): void {
    // No 'visibility' => 'public': there is no URL that works without the
    // session, and inventing one would hand out a link the disk never serves.
    $disk = fidfNoTemporaryUrlDisk('fidf_private_disk');
    $media = fidfInsertMedia(TestOwner::class, (string) Str::uuid(), $disk);

    expect(FileItemDTO::fromModel($media)->publicUrl)->toBeNull();
});
