<?php

namespace Lvntr\StarterKit\Domain\Media\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Action: Upload a file to a media collection, replacing any existing one.
 */
class UploadMediaAction extends BaseAction
{
    public function execute(Model $model, Request $request, string $collection, ?string $inputName = null): void
    {
        $inputName ??= $collection;

        // Snapshot the ids BEFORE the write. Clearing first — which this action
        // used to do — destroys the current avatar outright when the incoming
        // file then fails to store (unreadable temp file, full or unreachable
        // disk), leaving the user with no image at all. Adding first means a
        // failed upload simply leaves the existing one in place.
        $previousIds = $model->getMedia($collection)->pluck('id')->all();

        $model->addMediaFromRequest($inputName)->toMediaCollection($collection);

        if ($previousIds === []) {
            return;
        }

        // Queried through the relation rather than getMedia(), whose result the
        // model may still be caching from before the write. A `singleFile()`
        // collection has already trimmed these itself, in which case the query
        // simply returns nothing.
        //
        // Deleted one by one on purpose: Spatie removes the physical file from
        // its MediaObserver, which a mass delete() would bypass.
        $model->media()
            ->where('collection_name', $collection)
            ->whereIn('id', $previousIds)
            ->get()
            ->each(static fn (Media $media) => $media->delete());
    }
}
