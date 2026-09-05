<?php

namespace Lvntr\StarterKit\Domain\FileManager\Actions;

use Lvntr\StarterKit\Domain\FileManager\Concerns\ServesContextMedia;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadFileAction extends FileManagerAction
{
    use ServesContextMedia;

    /**
     * The context guard and the disk lookup moved to ServesContextMedia so the
     * preview route cannot drift away from this one; the behaviour is
     * unchanged (FilesystemAdapter::download() is response(..., 'attachment')).
     *
     * The union return type is kept as-is: it is part of the published
     * controller signature and narrowing it would be a public API break.
     */
    public function execute(FileManagerContextDTO $context, Media $media): BinaryFileResponse|StreamedResponse
    {
        return $this->streamContextMedia($context, $media, 'attachment');
    }
}
