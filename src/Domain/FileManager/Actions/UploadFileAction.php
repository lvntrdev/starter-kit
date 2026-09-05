<?php

namespace Lvntr\StarterKit\Domain\FileManager\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileItemDTO;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Exceptions\DomainRuleException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Upload one or more files into a folder (or root) of the given context.
 */
class UploadFileAction extends FileManagerAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function execute(
        FileManagerContextDTO $context,
        array $files,
        ?string $folderId = null,
        ?string $folderName = null,
    ): array {
        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        // If the caller did not specify an explicit folder_id but asked
        // for a managed folder by name, resolve/create it idempotently.
        if ($folderId === null && $folderName !== null && $folderName !== '') {
            $folderId = $this->ensureManagedFolder($context, $folderName, $folderModel);
        }

        if ($folderId !== null) {
            $folderModel::query()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->where('id', $folderId)
                ->firstOrFail();
        }

        if ($files === []) {
            throw new DomainRuleException(__('sk-file-manager.errors.no_files'));
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

            $uploaded[] = FileItemDTO::fromModel($media->refresh(), $context)->toArray();
        }

        return $uploaded;
    }

    /**
     * Atomically fetch (or lazily create) a root-level folder with the given
     * name inside the context. Soft-deleted matches are restored in place so
     * the (owner_type, owner_id, parent_id, name) unique index — which does
     * not include deleted_at — cannot drive the query into a constraint
     * violation loop. The create branch is wrapped in a try/catch so that
     * two concurrent uploads racing on a fresh name converge on a single
     * folder row instead of one failing with a 500.
     *
     * @param  class-string<Model>  $folderModel
     */
    private function ensureManagedFolder(FileManagerContextDTO $context, string $name, string $folderModel): string
    {
        return DB::transaction(function () use ($context, $name, $folderModel): string {
            $existing = $folderModel::withTrashed()
                ->where('owner_type', $context->ownerType)
                ->where('owner_id', $context->ownerId)
                ->whereNull('parent_id')
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                return $existing->id;
            }

            try {
                return $folderModel::query()->create([
                    'owner_type' => $context->ownerType,
                    'owner_id' => $context->ownerId,
                    'parent_id' => null,
                    'name' => $name,
                ])->id;
            } catch (QueryException) {
                // Lost the race with a concurrent request — refetch.
                return $folderModel::withTrashed()
                    ->where('owner_type', $context->ownerType)
                    ->where('owner_id', $context->ownerId)
                    ->whereNull('parent_id')
                    ->where('name', $name)
                    ->firstOrFail()
                    ->id;
            }
        });
    }
}
