<?php

namespace Lvntr\StarterKit\Domain\FileManager\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves the concrete Media Eloquent model class from the
 * media-library config so consumers can extend or swap the model
 * (e.g. to add SoftDeletes) without forking vendor code.
 *
 * @phpstan-type MediaClass class-string<Media>
 */
trait ResolvesMediaModel
{
    /**
     * Return the configured media model class.
     *
     * Falls back to Spatie's base model when the config key is absent,
     * preserving behaviour on minimal / test installs.
     *
     * @return class-string<Media>
     */
    protected function mediaModel(): string
    {
        /** @var class-string<Media> $class */
        $class = config('media-library.media_model', Media::class);

        return $class;
    }

    /**
     * Start a media query that INCLUDES soft-deleted (trash) rows when the
     * configured model supports SoftDeletes, falling back to a plain query
     * otherwise.
     *
     * `withTrashed()` is a builder macro registered by the SoftDeletes trait —
     * calling it on a bare install (base Media model without SoftDeletes)
     * would throw a BadMethodCallException, so the capability is
     * feature-detected. On bare installs there is no trash concept and the
     * plain query already sees every row, so behaviour is identical.
     *
     * @return Builder<Media>
     */
    protected function mediaQueryWithTrashed(): Builder
    {
        $mediaModel = $this->mediaModel();

        if (in_array(SoftDeletes::class, class_uses_recursive($mediaModel), true)) {
            return $mediaModel::withTrashed();
        }

        return $mediaModel::query();
    }

    /**
     * Total bytes consumed by ALL media in the system, across every owner /
     * collection / context, including soft-deleted (trash) entries —
     * represents true disk usage for quota accounting.
     *
     * Goes through mediaQueryWithTrashed() rather than calling withTrashed()
     * directly: on a bare install (Spatie's base Media model, no SoftDeletes)
     * that macro does not exist, and an unguarded call throws
     * BadMethodCallException on every upload the quota check touches.
     */
    protected function computeStorageUsed(): int
    {
        /** @var object{s: int|string}|null $row */
        $row = $this->mediaQueryWithTrashed()
            ->selectRaw('coalesce(sum(size), 0) as s')
            ->first();

        return $row ? (int) $row->s : 0;
    }

    /**
     * Maximum allowed storage in bytes, read from config.
     */
    protected function storageQuotaBytes(): int
    {
        return (int) config('file-manager.settings.storage_quota_gb', 10) * 1024 * 1024 * 1024;
    }
}
