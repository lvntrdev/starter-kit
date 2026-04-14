<?php

namespace App\Domain\FileManager\Actions;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadFileAction extends BaseAction
{
    public function execute(FileManagerContextDTO $context, Media $media): BinaryFileResponse|StreamedResponse
    {
        if (
            $media->collection_name !== 'files'
            || $media->model_type !== $context->ownerType
            || (string) $media->model_id !== $context->ownerId
        ) {
            throw new LogicException(__('file-manager.errors.file_out_of_context'));
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        return $disk->download($path, $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
    }
}
