<?php

/*
|--------------------------------------------------------------------------
| Restore — sibling name conflict (SK-SYS-008)
|--------------------------------------------------------------------------
|
| CreateFolderAction refuses a duplicate name under the same parent, so the
| trash must not be a way around it: delete a folder, create a new one under
| the same name, restore the old one. The unique index does NOT close this at
| the root, because MySQL and SQLite treat two NULL parent_id values as
| distinct.
|
| Covered here:
|
|   A) root-level collision is rejected (the index cannot see it)
|   B) the index already covers a nested namesake, but not a root one
|   C) a restore with no live sibling of that name still succeeds
|   D) a trashed sibling of the same name does NOT block the restore
|
*/

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Domain\FileManager\Actions\RestoreItemAction;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Exceptions\DomainRuleException;
use Lvntr\StarterKit\Tests\Stubs\TestFileFolder;
use Lvntr\StarterKit\Tests\Stubs\TestOwner;

function restoreContext(string $ownerId): FileManagerContextDTO
{
    $owner = new TestOwner;
    $owner->setAttribute('id', $ownerId);
    $owner->exists = false;

    return new FileManagerContextDTO(
        context: 'test-owner',
        contextId: $ownerId,
        owner: $owner,
        ownerType: 'test-owner',
        ownerId: $ownerId,
    );
}

/**
 * @param  string|null  $parentId  null = root level
 */
function makeFolder(string $ownerId, string $name, ?string $parentId = null, bool $trashed = false): TestFileFolder
{
    $folder = TestFileFolder::query()->create([
        'name' => $name,
        'parent_id' => $parentId,
        'owner_type' => 'test-owner',
        'owner_id' => $ownerId,
    ]);

    if ($trashed) {
        $folder->delete();
    }

    return $folder;
}

// ── A) root level — the unique index cannot catch this ───────────────────────

it('refuses to restore a root folder whose name is taken by a live sibling', function (): void {
    $ownerId = (string) Str::uuid();
    $context = restoreContext($ownerId);

    $trashed = makeFolder($ownerId, 'Invoices', null, trashed: true);
    makeFolder($ownerId, 'Invoices');

    expect(fn () => app(RestoreItemAction::class)->execute($context, 'folder', $trashed->id))
        ->toThrow(DomainRuleException::class);

    // Still in trash — the guard runs BEFORE the transaction.
    expect(TestFileFolder::onlyTrashed()->find($trashed->id))->not->toBeNull();
});

// ── B) why the guard exists: the index covers nested, not root ───────────────

it('lets a root namesake be created beside a trashed folder but not a nested one', function (): void {
    $ownerId = (string) Str::uuid();

    // Nested: the (owner, parent_id, name) unique index counts the soft-deleted
    // row, so the database itself refuses the namesake. Nothing can collide.
    $parent = makeFolder($ownerId, 'Root');
    makeFolder($ownerId, 'Reports', $parent->id, trashed: true);

    expect(fn () => makeFolder($ownerId, 'Reports', $parent->id))
        ->toThrow(UniqueConstraintViolationException::class);

    // Root: parent_id is NULL, which MySQL and SQLite treat as distinct per row,
    // so the same index lets the namesake through. That is the hole the
    // restore-time guard closes.
    makeFolder($ownerId, 'Invoices', null, trashed: true);

    expect(makeFolder($ownerId, 'Invoices')->exists)->toBeTrue();
});

// ── C) no collision — restore still works ────────────────────────────────────

it('restores a folder when no live sibling holds its name', function (): void {
    $ownerId = (string) Str::uuid();
    $context = restoreContext($ownerId);

    $trashed = makeFolder($ownerId, 'Archive', null, trashed: true);

    app(RestoreItemAction::class)->execute($context, 'folder', $trashed->id);

    expect(TestFileFolder::query()->find($trashed->id))->not->toBeNull();
});

// ── D) a trashed namesake is not a conflict ──────────────────────────────────

it('restores a folder when the only same-named sibling is itself trashed', function (): void {
    $ownerId = (string) Str::uuid();
    $context = restoreContext($ownerId);

    $trashed = makeFolder($ownerId, 'Drafts', null, trashed: true);
    makeFolder($ownerId, 'Drafts', null, trashed: true);

    app(RestoreItemAction::class)->execute($context, 'folder', $trashed->id);

    expect(TestFileFolder::query()->find($trashed->id))->not->toBeNull();
});

// ── E) another owner's folder of the same name is irrelevant ─────────────────

it('ignores a same-named folder that belongs to a different owner', function (): void {
    $ownerId = (string) Str::uuid();
    $context = restoreContext($ownerId);

    $trashed = makeFolder($ownerId, 'Shared', null, trashed: true);
    makeFolder((string) Str::uuid(), 'Shared');

    app(RestoreItemAction::class)->execute($context, 'folder', $trashed->id);

    expect(TestFileFolder::query()->find($trashed->id))->not->toBeNull();
});
