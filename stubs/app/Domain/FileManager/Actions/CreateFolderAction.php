<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use Illuminate\Support\Facades\Auth;
use LogicException;

class CreateFolderAction extends BaseAction
{
    public function execute(FileManagerContextDTO $context, string $name, ?string $parentId = null): FileFolder
    {
        if ($parentId !== null) {
            FileFolder::query()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $parentId)
                ->firstOrFail();
        }

        $exists = FileFolder::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new LogicException(__('sk-file-manager.errors.duplicate_folder'));
        }

        return FileFolder::create([
            'parent_id' => $parentId,
            'name' => $name,
            'owner_type' => $context->ownerType,
            'owner_id' => $context->ownerId,
            'created_by' => Auth::id(),
        ]);
    }
}
