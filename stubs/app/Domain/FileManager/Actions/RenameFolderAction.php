<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use Illuminate\Database\QueryException;
use LogicException;

class RenameFolderAction extends BaseAction
{
    public function execute(FileManagerContextDTO $context, FileFolder $folder, string $name): FileFolder
    {
        $this->assertBelongsToContext($context, $folder);

        // Pre-check handles parent_id=NULL where the unique index does not
        // enforce uniqueness (SQLite/MySQL treat NULL as distinct).
        $exists = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('parent_id', $folder->parent_id)
            ->where('name', $name)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($exists) {
            throw new LogicException(__('sk-file-manager.errors.duplicate_folder'));
        }

        try {
            $folder->update(['name' => $name]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new LogicException(__('sk-file-manager.errors.duplicate_folder'));
            }

            throw $e;
        }

        return $folder->refresh();
    }

    private function assertBelongsToContext(FileManagerContextDTO $context, FileFolder $folder): void
    {
        if ($folder->owner_type !== $context->ownerType || (string) $folder->owner_id !== $context->ownerId) {
            throw new LogicException(__('sk-file-manager.errors.folder_out_of_context'));
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
