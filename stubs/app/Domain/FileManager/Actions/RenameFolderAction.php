<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use LogicException;

class RenameFolderAction extends BaseAction
{
    public function execute(FileManagerContextDTO $context, FileFolder $folder, string $name): FileFolder
    {
        $this->assertBelongsToContext($context, $folder);

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

        $folder->update(['name' => $name]);

        return $folder->refresh();
    }

    private function assertBelongsToContext(FileManagerContextDTO $context, FileFolder $folder): void
    {
        if ($folder->owner_type !== $context->ownerType || (string) $folder->owner_id !== $context->ownerId) {
            throw new LogicException(__('sk-file-manager.errors.folder_out_of_context'));
        }
    }
}
