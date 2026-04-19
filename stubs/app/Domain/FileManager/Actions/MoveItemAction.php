<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Move a folder or a file to another (or root) folder within the same context.
 *
 * All moves are metadata-only — physical paths are derived from owner+mediaUuid
 * by MediaPathGenerator, so `folder_id` updates suffice.
 */
class MoveItemAction extends BaseAction
{
    public function execute(
        FileManagerContextDTO $context,
        string $itemType,
        string $itemId,
        ?string $targetFolderId,
    ): void {
        $this->assertTargetValid($context, $targetFolderId);

        match ($itemType) {
            'folder' => $this->moveFolder($context, $itemId, $targetFolderId),
            'file' => $this->moveFile($context, $itemId, $targetFolderId),
            default => throw new LogicException("Unsupported item type: {$itemType}"),
        };
    }

    private function assertTargetValid(FileManagerContextDTO $context, ?string $targetFolderId): void
    {
        if ($targetFolderId === null) {
            return;
        }

        $exists = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('id', $targetFolderId)
            ->exists();

        if (! $exists) {
            throw new LogicException(__('sk-file-manager.errors.target_missing'));
        }
    }

    private function moveFolder(FileManagerContextDTO $context, string $folderId, ?string $targetFolderId): void
    {
        $folder = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('id', $folderId)
            ->firstOrFail();

        if ($targetFolderId !== null && $this->wouldCreateCycle($context, $folder, $targetFolderId)) {
            throw new LogicException(__('sk-file-manager.errors.move_cycle'));
        }

        // Pre-check handles parent_id=NULL where the unique index does not
        // enforce uniqueness. The catch guards the narrow race window.
        $duplicate = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('parent_id', $targetFolderId)
            ->where('name', $folder->name)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($duplicate) {
            throw new LogicException(__('sk-file-manager.errors.duplicate_folder'));
        }

        try {
            $folder->update(['parent_id' => $targetFolderId]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new LogicException(__('sk-file-manager.errors.duplicate_folder'));
            }

            throw $e;
        }
    }

    private function moveFile(FileManagerContextDTO $context, string $mediaId, ?string $targetFolderId): void
    {
        $media = Media::query()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->where('id', (int) $mediaId)
            ->firstOrFail();

        $media->folder_id = $targetFolderId;
        $media->save();
    }

    /**
     * Walk up from `targetFolderId` toward root using a single pre-loaded
     * id → parent_id map, avoiding one SELECT per ancestor.
     */
    private function wouldCreateCycle(FileManagerContextDTO $context, FileFolder $folder, string $targetFolderId): bool
    {
        /** @var Collection<string, string|null> $parents */
        $parents = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->pluck('parent_id', 'id');

        $currentId = $targetFolderId;
        $visited = [];

        while ($currentId !== null) {
            if ((string) $currentId === (string) $folder->id) {
                return true;
            }

            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;

            $currentId = $parents[$currentId] ?? null;
        }

        return false;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
