<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Cascade-delete a folder: its subfolders (DB cascade) AND every Media record
 * contained anywhere beneath it (Media is removed via Spatie so files on disk
 * are cleaned up too).
 */
class DeleteFolderAction extends BaseAction
{
    public function execute(FileManagerContextDTO $context, FileFolder $folder): void
    {
        if ($folder->owner_type !== $context->ownerType || (string) $folder->owner_id !== $context->ownerId) {
            throw new LogicException(__('sk-file-manager.errors.folder_out_of_context'));
        }

        DB::transaction(function () use ($context, $folder) {
            $descendantIds = $this->collectDescendantIds($folder);
            $folderIds = [...$descendantIds, (string) $folder->id];

            Media::query()
                ->where('model_type', $context->ownerType)
                ->where('model_id', $context->ownerId)
                ->where('collection_name', 'files')
                ->whereIn('folder_id', $folderIds)
                ->get()
                ->each(fn (Media $media) => $media->delete());

            if ($descendantIds !== []) {
                FileFolder::query()->whereIn('id', $descendantIds)->delete();
            }

            $folder->delete();
        });
    }

    /**
     * @return array<int, string>
     */
    private function collectDescendantIds(FileFolder $folder): array
    {
        $ids = [];
        $stack = [(string) $folder->id];

        while ($stack !== []) {
            $parentId = array_shift($stack);
            $children = FileFolder::query()->where('parent_id', $parentId)->pluck('id')->all();

            foreach ($children as $childId) {
                $ids[] = (string) $childId;
                $stack[] = (string) $childId;
            }
        }

        return $ids;
    }
}
