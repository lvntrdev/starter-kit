<?php

/*
|--------------------------------------------------------------------------
| FileManager — Preview Route Authorization (Task 2 regression)
|--------------------------------------------------------------------------
|
| `file-manager.files.preview` is the in-app replacement for a raw, permanent,
| unauthenticated Media::getUrl() link (FileItemDTO's old fallback on any disk
| without temporary-URL support). It exists ONLY to be at least as safe as
| download() — same authorizeRead() gate at the controller, same
| ServesContextMedia context guard at the data-access boundary — while
| additionally forcing 'attachment' for anything that is not on a conservative
| passively-rendered allowlist, so an inline response can never become stored
| XSS against the admin origin.
|
| Two harness traps documented by Task 2, both worked around below instead of
| rediscovered:
|   (a) Storage::fake() ALWAYS installs a temporary-URL callback, so it cannot
|       be used to reach PreviewFileAction's real streaming branch when the
|       test also wants a disk WITHOUT temporary-URL support — a real 'local'
|       disk (not faked) is used here for the disk-serving tests.
|   (b) The package's ApiExceptionHandler is not wired in Testbench, so a
|       DomainRuleException thrown inside the route surfaces as an uncaught
|       500 over HTTP, not as a 422 envelope. The out-of-context and
|       wrong-collection cases below assert on the THROWN exception
|       (withoutExceptionHandling), never on a status code the package's own
|       mapping would produce in a real consumer app.
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
use Lvntr\StarterKit\Exceptions\DomainRuleException;
use Lvntr\StarterKit\Tests\Stubs\TestMedia;
use Lvntr\StarterKit\Tests\Stubs\TestOwner;

// ──────────────────────────────────────────────────────────────────────────────
// Yardımcılar
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Registers a throwaway context whose owner is {@see TestOwner}: the built-in
 * `user` context auto-resolves to `App\Models\User`, which does not exist in
 * the package's own Testbench suite. Registering our own context sidesteps
 * that without touching the built-in `global`/`user` conventions any other
 * test relies on.
 *
 * authorize() only ever grants `read` to the actor whose key matches the
 * context owner's key — enough to prove the controller's authorizeRead() gate
 * runs, without a real permission table.
 */
function fmPreviewRegisterContext(string $key = 'preview_ctx'): void
{
    // ContextRegistry has no singleton binding in the package container — a
    // plain app(ContextRegistry::class) resolves a NEW instance (with only the
    // built-in `global` context) on every call, so a registration here would
    // never be seen by the instance FileManagerRequest resolves later in the
    // same request. Binding it as a singleton first is what a real consumer's
    // provider does before calling register().
    app()->singleton(ContextRegistry::class);

    app(ContextRegistry::class)->register($key, [
        'model' => TestOwner::class,
        'path' => $key.'/{id}/files',
        'resolve' => fn (?string $id) => (new TestOwner)->forceFill(['id' => $id]),
        'authorize' => fn (Model $actor, string $ability, Model $owner): bool => $ability === 'read'
            && (string) $actor->getKey() === (string) $owner->getKey(),
    ]);
}

/**
 * Eloquent + Authenticatable actor with an arbitrary string id — matches
 * against the context owner's key by value only (see fmPreviewRegisterContext).
 */
function fmPreviewActor(string $id): Authenticatable
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

    return $actor->forceFill(['id' => $id]);
}

/**
 * Inserts a `media` row directly, bypassing FileAdder — full control over
 * collection_name/mime_type/model_type, which the preview guard branches on.
 */
function fmPreviewInsertMedia(
    string $modelType,
    string $modelId,
    string $collection = 'files',
    string $mimeType = 'image/png',
    string $fileName = 'preview.png',
): TestMedia {
    $id = DB::table('media')->insertGetId([
        'model_type' => $modelType,
        'model_id' => $modelId,
        'uuid' => Str::uuid()->toString(),
        'collection_name' => $collection,
        'name' => pathinfo($fileName, PATHINFO_FILENAME),
        'file_name' => $fileName,
        'mime_type' => $mimeType,
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

// ──────────────────────────────────────────────────────────────────────────────
// A) Authorized preview — inline for an allowlisted MIME type
// ──────────────────────────────────────────────────────────────────────────────

it('serves an authorized file with Content-Disposition: inline', function (): void {
    Storage::fake('public');

    fmPreviewRegisterContext();
    // context_id is validated as a UUID by FileManagerRequest::contextRules()
    // regardless of the context's own key shape, so every owner id below is one.
    $ownerId = (string) Str::uuid();
    $actor = fmPreviewActor($ownerId);
    $media = fmPreviewInsertMedia(TestOwner::class, $ownerId);
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'preview-bytes');

    $response = $this->actingAs($actor)->getJson(route('file-manager.files.preview', [
        'media' => $media->getKey(),
        'context' => 'preview_ctx',
        'context_id' => $ownerId,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

// ──────────────────────────────────────────────────────────────────────────────
// B) Media outside the caller's context — DomainRuleException, never bytes
// ──────────────────────────────────────────────────────────────────────────────

it('rejects a media row outside the caller context', function (): void {
    Storage::fake('public');

    fmPreviewRegisterContext();
    $ownerId = (string) Str::uuid();
    $actor = fmPreviewActor($ownerId);
    // Belongs to a DIFFERENT owner id under the same context.
    $media = fmPreviewInsertMedia(TestOwner::class, (string) Str::uuid());
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'preview-bytes');

    $this->actingAs($actor);
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson(route('file-manager.files.preview', [
        'media' => $media->getKey(),
        'context' => 'preview_ctx',
        'context_id' => $ownerId,
    ])))->toThrow(DomainRuleException::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// C) Non-`files` collection — never served, regardless of context match
// ──────────────────────────────────────────────────────────────────────────────

it('rejects a media row outside the files collection', function (): void {
    Storage::fake('public');

    fmPreviewRegisterContext();
    $ownerId = (string) Str::uuid();
    $actor = fmPreviewActor($ownerId);
    // Same owner/context, but e.g. an avatar collection — must never be
    // reachable through the FileManager preview route.
    $media = fmPreviewInsertMedia(TestOwner::class, $ownerId, collection: 'avatars');
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'preview-bytes');

    $this->actingAs($actor);
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson(route('file-manager.files.preview', [
        'media' => $media->getKey(),
        'context' => 'preview_ctx',
        'context_id' => $ownerId,
    ])))->toThrow(DomainRuleException::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// D) Unauthenticated request never reaches the file
// ──────────────────────────────────────────────────────────────────────────────

it('does not stream any bytes for an unauthenticated request', function (): void {
    Storage::fake('public');

    fmPreviewRegisterContext();
    $ownerId = (string) Str::uuid();
    $media = fmPreviewInsertMedia(TestOwner::class, $ownerId);
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'preview-bytes');

    // No actingAs(): the route mounts under the package's ['web', 'auth',
    // 'verified'] tier (StarterKitServiceProvider::moduleRouteRegistry()), so
    // Authenticate::unauthenticated() fires before FileManagerAuthorizer ever
    // runs — an unauthenticated caller never reaches the context guard or the
    // disk. A JSON request makes that middleware answer 401 instead of
    // redirecting to a `login` route this package suite never defines; the
    // security-relevant assertion is the one the body backs: no file bytes
    // ever left the server.
    $response = $this->getJson(route('file-manager.files.preview', [
        'media' => $media->getKey(),
        'context' => 'preview_ctx',
        'context_id' => $ownerId,
    ]));

    $response->assertUnauthorized();
    expect($response->getContent())->not->toContain('preview-bytes');
});

// ──────────────────────────────────────────────────────────────────────────────
// E) Range requests — the inline preview player must be seekable
// ──────────────────────────────────────────────────────────────────────────────

it('answers a Range request with 206 and a Content-Range', function (): void {
    // Regression guard for the streaming shape, not the authorization gate:
    // FilesystemAdapter::response() is a StreamedResponse, which ignores Range
    // entirely and replies 200 with the whole body. FilePreviewModal binds
    // file.url straight into a <video controls> / <audio controls> element, so without a
    // 206 the player cannot seek (and Safari refuses to start playback at all).
    Storage::fake('public');

    fmPreviewRegisterContext();
    $ownerId = (string) Str::uuid();
    $actor = fmPreviewActor($ownerId);
    $media = fmPreviewInsertMedia(TestOwner::class, $ownerId, mimeType: 'video/mp4', fileName: 'clip.mp4');
    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'ABCDEFGHIJ');

    $response = $this->actingAs($actor)->get(
        route('file-manager.files.preview', [
            'media' => $media->getKey(),
            'context' => 'preview_ctx',
            'context_id' => $ownerId,
        ]),
        ['Range' => 'bytes=2-5'],
    );

    $response->assertStatus(206);
    expect($response->headers->get('Content-Range'))->toBe('bytes 2-5/10')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->streamedContent())->toBe('CDEF');
});
