<?php

/*
|--------------------------------------------------------------------------
| Physical delete happens AFTER the row's delete commits
|--------------------------------------------------------------------------
|
| Spatie removes a media object from disk in MediaObserver::deleted(), which
| fires inside Model::delete() — i.e. inside whatever transaction the caller
| opened. Every kit force-delete path opens one: the folder cascade in
| PermanentlyDeleteItemAction, BulkDeleteAction, EmptyTrashAction,
| DeleteFolderAction, and every transactional ActionPipeline.
|
| So the old order was: delete row, delete file, THEN commit. A rollback
| anywhere later in that transaction — a deadlock, a constraint, a failure on
| the tenth of fifty files — brought every row back while every already-removed
| file stayed gone. The user sees their files listed and every download 404s.
| A row pointing at bytes that no longer exist cannot be undone.
|
| DeferredDeleteMediaFilesystem moves the removal behind afterCommit(). What is
| locked here:
|
|   - a rollback leaves the file ON DISK, matching the restored row;
|   - the file is still there WHILE the transaction is open, and gone once it
|     commits — the ordering, not just the end state;
|   - with no transaction open the timing is unchanged (removed inline), so
|     file-manager:purge-trash and a bare forceDelete() behave as before;
|   - a removal that fails after the commit logs the orphan instead of throwing
|     out of a DELETE that has already succeeded.
|
*/

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Support\DeferredDeleteMediaFilesystem;
use Lvntr\StarterKit\Tests\Stubs\TestMedia;
use Spatie\MediaLibrary\MediaCollections\Filesystem as MediaFilesystem;

function dpdMediaWithFile(): TestMedia
{
    $id = DB::table('media')->insertGetId([
        'model_type' => 'test-owner',
        'model_id' => (string) Str::uuid(),
        'uuid' => Str::uuid()->toString(),
        'collection_name' => 'files',
        'name' => 'deferred-'.Str::random(6),
        'file_name' => 'deferred-test.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'conversions_disk' => null,
        'size' => 512,
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

    /** @var TestMedia $media */
    $media = TestMedia::withTrashed()->find($id);

    Storage::disk('public')->put($media->getPathRelativeToRoot(), 'dummy-content');

    return $media;
}

it('binds the deferring filesystem in place of Spatie\'s', function (): void {
    // The whole guarantee rides on this ONE choke point: every removal — every
    // action, the observer, the purge command — goes through it, so no caller
    // has to remember the ordering.
    expect(app(MediaFilesystem::class))->toBeInstanceOf(DeferredDeleteMediaFilesystem::class);
});

it('keeps the file on disk when the transaction that deleted the row rolls back', function (): void {
    $first = dpdMediaWithFile();
    $second = dpdMediaWithFile();

    $firstPath = $first->getPathRelativeToRoot();
    $secondPath = $second->getPathRelativeToRoot();

    // The folder-cascade shape: several force-deletes in one transaction, and
    // something later in it fails.
    try {
        DB::transaction(function () use ($first, $second): void {
            $first->forceDelete();
            $second->forceDelete();

            throw new RuntimeException('deadlock on the last statement');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Rows came back...
    expect(TestMedia::withTrashed()->find($first->id))->not->toBeNull()
        ->and(TestMedia::withTrashed()->find($second->id))->not->toBeNull();

    // ...and so did their files, because they were never removed.
    expect(Storage::disk('public')->exists($firstPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($secondPath))->toBeTrue();
});

it('removes the file only once the transaction commits, not while it is open', function (): void {
    $media = dpdMediaWithFile();
    $path = $media->getPathRelativeToRoot();

    $existedDuringTransaction = null;

    DB::transaction(function () use ($media, $path, &$existedDuringTransaction): void {
        $media->forceDelete();

        // The row is gone from this transaction's view, but the bytes must
        // still be there: nothing is durable yet.
        $existedDuringTransaction = Storage::disk('public')->exists($path);
    });

    expect($existedDuringTransaction)->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeFalse()
        ->and(TestMedia::withTrashed()->find($media->id))->toBeNull();
});

it('still removes the file when no transaction is open, so non-transactional paths are unchanged', function (): void {
    // file-manager:purge-trash deletes row by row with no transaction of its
    // own. afterCommit() invokes the callback immediately at transaction level
    // zero, so the removal must still happen — a deferral that quietly skipped
    // it would orphan every purged row's file.
    //
    // Note this asserts the OUTCOME, not the timing: under RefreshDatabase the
    // wrapper transaction is excluded from callbackApplicableTransactions(), so
    // level zero is what this path sees either way.
    $media = dpdMediaWithFile();
    $path = $media->getPathRelativeToRoot();

    $media->forceDelete();

    expect(Storage::disk('public')->exists($path))->toBeFalse();
});

it('logs an orphaned file instead of throwing when the removal fails after the commit', function (): void {
    $media = dpdMediaWithFile();

    Log::spy();

    // Break the path generator AFTER the row exists: Spatie resolves it inside
    // removeAllFiles() and, unlike a disk error, does not swallow that failure.
    config()->set('media-library.path_generator', 'No\\Such\\PathGenerator');

    // The commit must NOT surface it — the DELETE has already succeeded, and an
    // orphaned file is recoverable while a row without its bytes is not.
    DB::transaction(function () use ($media): void {
        $media->forceDelete();
    });

    expect(TestMedia::withTrashed()->find($media->id))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'orphaned')
            && $context['media_id'] === $media->id
            && $context['disk'] === 'public')
        ->once();
});
