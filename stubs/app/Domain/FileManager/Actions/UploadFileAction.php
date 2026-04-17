<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileItemDTO;
use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\FileFolder;
use Illuminate\Http\UploadedFile;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload one or more files into a folder (or root) of the given context.
 */
class UploadFileAction extends BaseAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function execute(FileManagerContextDTO $context, array $files, ?string $folderId = null): array
    {
        if ($folderId !== null) {
            FileFolder::query()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $folderId)
                ->firstOrFail();
        }

        if ($files === []) {
            throw new LogicException(__('sk-file-manager.errors.no_files'));
        }

        $uploaded = [];

        foreach ($files as $file) {
            /** @var Media $media */
            $media = $context->owner
                ->addMedia($file)
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->toMediaCollection('files');

            if ($folderId !== null) {
                $media->folder_id = $folderId;
                $media->save();
            }

            $uploaded[] = FileItemDTO::fromModel($media->refresh())->toArray();
        }

        return $uploaded;
    }
}
