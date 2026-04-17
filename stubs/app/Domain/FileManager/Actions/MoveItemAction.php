<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
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

        if ($targetFolderId !== null && $this->wouldCreateCycle($folder, $targetFolderId)) {
            throw new LogicException(__('sk-file-manager.errors.move_cycle'));
        }

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

        $folder->update(['parent_id' => $targetFolderId]);
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

    private function wouldCreateCycle(FileFolder $folder, string $targetFolderId): bool
    {
        $current = FileFolder::query()->find($targetFolderId);

        while ($current !== null) {
            if ((string) $current->id === (string) $folder->id) {
                return true;
            }
            $current = $current->parent_id ? FileFolder::query()->find($current->parent_id) : null;
        }

        return false;
    }
}
