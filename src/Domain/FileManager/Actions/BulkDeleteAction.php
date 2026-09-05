<?php

namespace Lvntr\StarterKit\Domain\FileManager\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;

/**
 * Delete multiple folders and/or files in one transaction.
 *
 * @phpstan-type ItemInput array{type: 'folder'|'file', id: string}
 */
class BulkDeleteAction extends FileManagerAction
{
    /**
     * $items arrives from request input, so each entry is guarded in the loop
     * below rather than assumed present — the shape mirrors that.
     *
     * @param  array<int, array{type?: string|null, id?: string|int|null}>  $items
     * @return array{folders: int, files: int}
     */
    public function execute(FileManagerContextDTO $context, array $items, bool $force = false): array
    {
        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        $folderIds = [];
        $fileIds = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) === 'folder' && ! empty($item['id'])) {
                $folderIds[] = (string) $item['id'];
            } elseif (($item['type'] ?? null) === 'file' && ! empty($item['id'])) {
                $fileIds[] = (int) $item['id'];
            }
        }

        return DB::transaction(function () use ($context, $folderIds, $fileIds, $force, $folderModel) {
            $deletedFiles = 0;
            $deletedFolders = 0;

            if ($fileIds !== []) {
                $files = ($force ? $this->mediaModel()::withTrashed() : $this->mediaModel()::query())
                    ->where('model_type', $context->ownerType)
                    ->where('model_id', $context->ownerId)
                    ->where('collection_name', 'files')
                    ->whereIn('id', $fileIds)
                    ->get();

                foreach ($files as $media) {
                    $force ? $media->forceDelete() : $media->delete();
                    $deletedFiles++;
                }
            }

            if ($folderIds !== []) {
                $rootFolders = ($force ? $folderModel::withTrashed() : $folderModel::query())
                    ->where('owner_type', $context->ownerType)
                    ->where('owner_id', $context->ownerId)
                    ->whereIn('id', $folderIds)
                    ->get();

                $childrenByParent = $this->buildChildrenMap($context, $force, $folderModel);

                foreach ($rootFolders as $folder) {
                    $descendants = $this->collectDescendantIds($folder, $childrenByParent);
                    $allIds = [...$descendants, (string) $folder->getKey()];

                    $media = ($force ? $this->mediaModel()::withTrashed() : $this->mediaModel()::query())
                        ->where('model_type', $context->ownerType)
                        ->where('model_id', $context->ownerId)
                        ->where('collection_name', 'files')
                        ->whereIn('folder_id', $allIds)
                        ->get();

                    foreach ($media as $m) {
                        $force ? $m->forceDelete() : $m->delete();
                        $deletedFiles++;
                    }

                    if ($descendants !== []) {
                        // Iterate per-model so the forceDeleted observer fires for
                        // favorites cleanup when force-deleting.
                        ($force ? $folderModel::withTrashed() : $folderModel::query())
                            ->whereIn('id', $descendants)
                            ->get()
                            ->each($force
                                ? fn (Model $f) => $f->forceDelete()
                                : fn (Model $f) => $f->delete()
                            );
                    }

                    $force ? $folder->forceDelete() : $folder->delete();
                    $deletedFolders++;
                }
            }

            return ['folders' => $deletedFolders, 'files' => $deletedFiles];
        });
    }

    /**
     * Build an in-memory parent_id => [childId, ...] map for the owner scope,
     * so descendant walks do not issue a query per level.
     *
     * @param  class-string<Model>  $folderModel
     * @return array<string, array<int, string>>
     */
    private function buildChildrenMap(FileManagerContextDTO $context, bool $withTrashed, string $folderModel): array
    {
        $rows = ($withTrashed ? $folderModel::withTrashed() : $folderModel::query())
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->get(['id', 'parent_id']);

        $map = [];
        foreach ($rows as $row) {
            $parentId = $row->parent_id === null ? '' : (string) $row->parent_id;
            $map[$parentId][] = (string) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<string, array<int, string>>  $childrenByParent
     * @return array<int, string>
     */
    private function collectDescendantIds(Model $folder, array $childrenByParent): array
    {
        $ids = [];
        $stack = [(string) $folder->getKey()];

        while ($stack !== []) {
            $parentId = array_shift($stack);
            $children = $childrenByParent[$parentId] ?? [];

            foreach ($children as $childId) {
                $ids[] = $childId;
                $stack[] = $childId;
            }
        }

        return $ids;
    }
}
