<?php

namespace Lvntr\StarterKit\Domain\FileManager\Queries;

use Illuminate\Database\Eloquent\Model;
use Lvntr\StarterKit\Domain\FileManager\Concerns\ResolvesMediaModel;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileItemDTO;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Returns all favorited folders and files for a given context owner.
 * Output shape is intentionally compatible with FolderContentsQuery.
 */
class FavoritesContentsQuery
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

        /** @var class-string<Model> $favoriteModel */
        $favoriteModel = config('file-manager.models.favorite', 'App\\Models\\FileFavorite');

        $favorites = $favoriteModel::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->get(['favoritable_type', 'favoritable_id']);

        $folderIds = $favorites
            ->where('favoritable_type', 'folder')
            ->pluck('favoritable_id')
            ->all();

        $fileIds = $favorites
            ->where('favoritable_type', 'file')
            ->pluck('favoritable_id')
            ->all();

        $folderModels = $folderIds === []
            ? collect()
            : $folderModel::query()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->whereIn('id', $folderIds)
                ->orderBy('name')
                ->get();

        $folders = $folderModels
            ->map(fn (Model $folder) => [
                'id' => (string) $folder->getKey(),
                'parent_id' => $folder->getAttribute('parent_id'),
                'name' => $folder->getAttribute('name'),
                'file_count' => 0, // favorites view does not aggregate subtree stats
                'total_size' => 0,
                'is_favorited' => true,
            ])
            ->values()
            ->all();

        $mediaModel = $this->mediaModel();
        $mediaModels = $fileIds === []
            ? collect()
            : $mediaModel::query()
                ->where('model_type', $context->ownerType)
                ->where('model_id', $context->ownerId)
                ->where('collection_name', 'files')
                ->whereIn('id', $fileIds)
                ->orderBy('name')
                ->get();

        $files = $mediaModels
            ->map(function (Media $media) use ($context) {
                $payload = FileItemDTO::fromModel($media, $context)->toArray();
                $payload['is_favorited'] = true;

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
