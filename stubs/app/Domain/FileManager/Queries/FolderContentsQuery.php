<?php

namespace App\Domain\FileManager\Queries;

use App\Domain\FileManager\DTOs\FileItemDTO;
use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Models\FileFolder;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Returns the immediate contents (sub-folders + files) for the given folder
 * within a context, with sorting and aggregate stats.
 */
class FolderContentsQuery
{
    /**
     * @param  array{sort?: 'name'|'size'|'date', direction?: 'asc'|'desc'}  $options
     * @return array{
     *     folder: array<string, mixed>|null,
     *     folders: array<int, array<string, mixed>>,
     *     files: array<int, array<string, mixed>>,
     *     stats: array{file_count: int, total_size: int},
     * }
     */
    public function execute(FileManagerContextDTO $context, ?string $folderId = null, array $options = []): array
    {
        $sort = $options['sort'] ?? 'name';
        $direction = $options['direction'] ?? 'asc';

        $folder = null;

        if ($folderId !== null) {
            $folder = FileFolder::query()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $folderId)
                ->firstOrFail();
        }

        $allFolders = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->get(['id', 'parent_id', 'name']);

        $childrenMap = [];
        foreach ($allFolders as $f) {
            $childrenMap[(string) ($f->parent_id ?? '')][] = (string) $f->id;
        }

        $folderList = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('parent_id', $folderId)
            ->orderBy('name')
            ->get();

        $folderStats = $this->computeFolderStats($context, $folderList, $childrenMap);

        $folders = $folderList
            ->map(fn (FileFolder $child) => [
                'id' => (string) $child->id,
                'parent_id' => $child->parent_id,
                'name' => $child->name,
                'file_count' => $folderStats[(string) $child->id]['count'] ?? 0,
                'total_size' => $folderStats[(string) $child->id]['size'] ?? 0,
            ])
            ->values()
            ->all();

        $mediaQuery = Media::query()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->where('folder_id', $folderId);

        $mediaQuery = match ($sort) {
            'size' => $mediaQuery->orderBy('size', $direction),
            'date' => $mediaQuery->orderBy('created_at', $direction),
            default => $mediaQuery->orderBy('name', $direction),
        };

        $mediaList = $mediaQuery->get();

        $files = $mediaList
            ->map(fn (Media $media) => FileItemDTO::fromModel($media)->toArray())
            ->values()
            ->all();

        $stats = $this->computeAggregateStats($context, $folderId, $childrenMap);

        return [
            'folder' => $folder ? [
                'id' => (string) $folder->id,
                'parent_id' => $folder->parent_id,
                'name' => $folder->name,
            ] : null,
            'folders' => $folders,
            'files' => $files,
            'stats' => $stats,
        ];
    }

    /**
     * Compute recursive file_count + total_size for each root folder.
     *
     * @param  Collection<int, FileFolder>  $rootFolders
     * @param  array<string, array<int, string>>  $childrenMap  parent_id → child ids
     * @return array<string, array{count: int, size: int}>
     */
    private function computeFolderStats(
        FileManagerContextDTO $context,
        Collection $rootFolders,
        array $childrenMap,
    ): array {
        if ($rootFolders->isEmpty()) {
            return [];
        }

        $subtreeMap = [];
        foreach ($rootFolders as $root) {
            $subtreeMap[(string) $root->id] = $this->collectSubtreeIds((string) $root->id, $childrenMap);
        }

        $allIds = array_unique(array_merge(...array_values($subtreeMap)));

        $stats = Media::query()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files')
            ->whereIn('folder_id', $allIds)
            ->selectRaw('folder_id, count(*) as c, coalesce(sum(size), 0) as s')
            ->groupBy('folder_id')
            ->get()
            ->keyBy('folder_id');

        $out = [];
        foreach ($subtreeMap as $rootId => $ids) {
            $count = 0;
            $size = 0;
            foreach ($ids as $id) {
                $row = $stats->get($id);
                if ($row) {
                    $count += (int) $row->c;
                    $size += (int) $row->s;
                }
            }
            $out[$rootId] = ['count' => $count, 'size' => $size];
        }

        return $out;
    }

    /**
     * Aggregate file count + size for the current folder and its whole subtree.
     *
     * When $folderId is null, covers all files in the context (root view).
     *
     * @param  array<string, array<int, string>>  $childrenMap
     * @return array{file_count: int, total_size: int}
     */
    private function computeAggregateStats(
        FileManagerContextDTO $context,
        ?string $folderId,
        array $childrenMap,
    ): array {
        $query = Media::query()
            ->where('model_type', $context->ownerType)
            ->where('model_id', $context->ownerId)
            ->where('collection_name', 'files');

        if ($folderId !== null) {
            $subtreeIds = $this->collectSubtreeIds($folderId, $childrenMap);
            $query->whereIn('folder_id', $subtreeIds);
        }

        /** @var object{c: int|string, s: int|string}|null $row */
        $row = $query->selectRaw('count(*) as c, coalesce(sum(size), 0) as s')->first();

        return [
            'file_count' => $row ? (int) $row->c : 0,
            'total_size' => $row ? (int) $row->s : 0,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $childrenMap
     * @return array<int, string>
     */
    private function collectSubtreeIds(string $rootId, array $childrenMap): array
    {
        $ids = [$rootId];
        $stack = [$rootId];

        while ($stack !== []) {
            $parent = array_shift($stack);
            foreach ($childrenMap[$parent] ?? [] as $child) {
                $ids[] = $child;
                $stack[] = $child;
            }
        }

        return $ids;
    }
}
