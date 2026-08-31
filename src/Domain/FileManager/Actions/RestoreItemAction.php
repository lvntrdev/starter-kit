<?php

namespace Lvntr\StarterKit\Domain\FileManager\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Exceptions\DomainRuleException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Restore a soft-deleted folder or file from trash back into the active tree.
 *
 * Refuses to restore an item whose parent folder is still trashed — reviving
 * an orphan would surface a confusing dangling row in the user's listing.
 *
 * When restoring a folder, all descendant folders and their contained files
 * (collection_name = 'files') are cascade-restored in a single transaction,
 * mirroring the cascade soft-delete performed by DeleteFolderAction.
 */
class RestoreItemAction extends FileManagerAction
{
    public function execute(FileManagerContextDTO $context, string $itemType, string $itemId): void
    {
        if (! in_array($itemType, ['folder', 'file'], true)) {
            throw new DomainRuleException(__('sk-file-manager.errors.invalid_trash_item_type'));
        }

        match ($itemType) {
            'folder' => $this->restoreFolder($context, $itemId),
            'file' => $this->restoreFile($context, $itemId),
        };
    }

    private function restoreFolder(FileManagerContextDTO $context, string $folderId): void
    {
        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        /** @var Model|null $folder */
        $folder = $folderModel::onlyTrashed()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('id', $folderId)
            ->first();

        if ($folder === null) {
            throw new DomainRuleException(__('sk-file-manager.errors.trash_item_not_found'));
        }

        if ($folder->getAttribute('parent_id') !== null) {
            /** @var Model|null $parent */
            $parent = $folderModel::withTrashed()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $folder->getAttribute('parent_id'))
                ->first();

            if ($parent === null) {
                // Parent permanently deleted — move to root to avoid orphan parent_id.
                $folder->setAttribute('parent_id', null);
            } elseif ($parent->trashed()) {
                throw new DomainRuleException(__('sk-file-manager.errors.restore_parent_trashed'));
            }
        }

        $this->guardAgainstSiblingNameConflict($context, $folder, $folderModel);

        DB::transaction(function () use ($context, $folder, $folderModel) {
            $this->cascadeRestore($context, $folder, $folderModel);
        });
    }

    private function restoreFile(FileManagerContextDTO $context, string $fileId): void
    {
        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        /** @var Media|null $media */
        $media = $this->mediaModel()::onlyTrashed()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->where('id', $fileId)
            ->first();

        if ($media === null) {
            throw new DomainRuleException(__('sk-file-manager.errors.trash_item_not_found'));
        }

        if ($media->folder_id !== null) {
            /** @var Model|null $parent */
            $parent = $folderModel::withTrashed()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $media->folder_id)
                ->first();

            if ($parent === null) {
                // Parent folder permanently deleted — move file to root to avoid
                // orphan folder_id reference (silent data loss otherwise).
                $media->folder_id = null;
            } elseif ($parent->trashed()) {
                throw new DomainRuleException(__('sk-file-manager.errors.restore_parent_trashed'));
            }
        }

        $media->restore();
    }

    /**
     * Refuse a restore that would land a second folder of the same name beside
     * an active sibling.
     *
     * CreateFolderAction already rejects a duplicate name, so without this the
     * trash is a way around that rule: delete a folder, create a new one under
     * the same name, restore the old one. The `(owner_type, owner_id,
     * parent_id, name)` unique index does not stop it either — MySQL and SQLite
     * treat two NULL `parent_id` values as distinct, so a root-level collision
     * passes straight through.
     *
     * The destination is the parent the folder will actually be restored INTO,
     * which is not necessarily its stored one: a permanently deleted parent has
     * already been rewritten to null by the caller.
     *
     * @param  class-string<Model>  $folderModel
     */
    private function guardAgainstSiblingNameConflict(FileManagerContextDTO $context, Model $folder, string $folderModel): void
    {
        $conflict = $folderModel::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('name', $folder->getAttribute('name'))
            ->whereKeyNot($folder->getKey());

        $parentId = $folder->getAttribute('parent_id');

        $parentId === null
            ? $conflict->whereNull('parent_id')
            : $conflict->where('parent_id', $parentId);

        if ($conflict->exists()) {
            throw new DomainRuleException(__('sk-file-manager.errors.restore_name_conflict'));
        }
    }

    /**
     * Restore the given folder, all its descendant folders, and every file
     * (collection_name = 'files') contained anywhere in that subtree.
     *
     * @param  class-string<Model>  $folderModel
     */
    private function cascadeRestore(FileManagerContextDTO $context, Model $folder, string $folderModel): void
    {
        $descendantIds = $this->collectTrashedDescendantIds($context, $folder, $folderModel);
        $folderIds = [...$descendantIds, (string) $folder->getKey()];

        // Restore all folders in the subtree (parent first, children after —
        // order does not matter for soft-delete restore but is cleaner).
        $folder->restore();

        if ($descendantIds !== []) {
            $folderModel::onlyTrashed()->whereIn('id', $descendantIds)->restore();
        }

        // Restore every 'files'-collection Media record belonging to this subtree.
        // $folderIds already includes $folder->id so files placed directly in the
        // root folder (no sub-folder) are also covered.
        $this->mediaModel()::onlyTrashed()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->whereIn('folder_id', $folderIds)
            ->restore();
    }

    /**
     * Walk the trashed folder subtree using a single pre-loaded parent_id map,
     * mirroring DeleteFolderAction::collectDescendantIds but scoped to trashed
     * rows so only soft-deleted descendants are returned.
     *
     * @param  class-string<Model>  $folderModel
     * @return array<int, string>
     */
    private function collectTrashedDescendantIds(FileManagerContextDTO $context, Model $folder, string $folderModel): array
    {
        // Load ALL trashed folders for this owner in one query to avoid per-level
        // queries when walking deep hierarchies.
        $rows = $folderModel::onlyTrashed()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->get(['id', 'parent_id']);

        $childrenByParent = [];
        foreach ($rows as $row) {
            $parentId = $row->parent_id === null ? '' : (string) $row->parent_id;
            $childrenByParent[$parentId][] = (string) $row->id;
        }

        $ids = [];
        $stack = [(string) $folder->getKey()];

        while ($stack !== []) {
            $parentId = array_shift($stack);
            foreach ($childrenByParent[$parentId] ?? [] as $childId) {
                $ids[] = $childId;
                $stack[] = $childId;
            }
        }

        return $ids;
    }
}
