<?php

namespace Lvntr\StarterKit\Domain\FileManager\Queries;

use Illuminate\Database\Eloquent\Model;
use Lvntr\StarterKit\Domain\FileManager\Concerns\ResolvesMediaModel;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileItemDTO;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Returns soft-deleted folders and files for a given context owner.
 * Output shape mirrors FolderContentsQuery so the frontend can reuse the
 * same grid component for the trash view.
 */
class TrashContentsQuery
{
    use ResolvesMediaModel;

    /**
     * @return array{
     *     folder: null,
     *     folders: array<int, array<string, mixed>>,
     *     files: array<int, array<string, mixed>>,
     *     stats: array{file_count: int, total_size: int, storage_used: int, storage_quota: int},
     * }
     */
    public function execute(FileManagerContextDTO $context): array
    {
        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        $allTrashedFolders = $folderModel::onlyTrashed()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->orderBy('deleted_at', 'desc')
            ->get();

        // Collect all trashed folder IDs so we can filter out nested children.
        // A folder whose parent is also trashed cannot be restored independently —
        // restoring the parent cascades down. Showing children separately is confusing
        // and triggers the "restore_parent_trashed" error on every attempt.
        $trashedFolderIds = $allTrashedFolders->map(fn (Model $f) => (string) $f->getKey())->flip()->all();

        $folderModels = $allTrashedFolders->filter(function (Model $folder) use ($trashedFolderIds) {
            $parentId = $folder->getAttribute('parent_id');

            return $parentId === null || ! isset($trashedFolderIds[(string) $parentId]);
        })->values();

        $folders = $folderModels
            ->map(fn (Model $folder) => [
                'id' => (string) $folder->getKey(),
                'parent_id' => $folder->getAttribute('parent_id'),
                'name' => $folder->getAttribute('name'),
                'file_count' => 0,
                'total_size' => 0,
                'is_favorited' => false,
                'deleted_at' => $folder->getAttribute('deleted_at')?->toIso8601String(),
            ])
            ->values()
            ->all();

        $mediaModel = $this->mediaModel();
        $allTrashedMedia = $mediaModel::onlyTrashed()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->orderBy('deleted_at', 'desc')
            ->get();

        // Hide files whose parent folder is also in trash — the folder restore will
        // cascade-restore them automatically.
        $mediaModels = $allTrashedMedia->filter(function (Media $media) use ($trashedFolderIds) {
            return $media->folder_id === null || ! isset($trashedFolderIds[(string) $media->folder_id]);
        })->values();

        $files = $mediaModels
            ->map(function (Media $media) use ($context) {
                $payload = FileItemDTO::fromModel($media, $context)->toArray();
                $payload['is_favorited'] = false;
                $payload['deleted_at'] = $media->deleted_at?->toIso8601String();

                return $payload;
            })
            ->values()
            ->all();

        return [
            'folder' => null,
            'folders' => $folders,
            'files' => $files,
            'stats' => [
                'file_count' => $mediaModels->count(),
                'total_size' => (int) $mediaModels->sum('size'),
                'storage_used' => $this->computeStorageUsed(),
                'storage_quota' => $this->storageQuotaBytes(),
            ],
        ];
    }
}
