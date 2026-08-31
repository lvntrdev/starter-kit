<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->uuidMorphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();
        });
    }

    /**
     * Drop the media table — but only when there is nothing in it to lose.
     *
     * ## Why this method exists at all
     *
     * It did not, and Laravel's migrator guards the call with `method_exists`:
     * `migrate:rollback` therefore SKIPPED this migration silently and deleted
     * its ledger row anyway. The table outlived its own record, and the next
     * `migrate` died on a table that already existed, with no rollback left to
     * undo it. That is the hole this closes.
     *
     * ## Why it REFUSES instead of dropping
     *
     * This file was published long ago and consumers have already run it. A
     * `down()` that drops unconditionally would retroactively turn a rollback
     * they have run safely before into one that destroys their media index —
     * the behaviour would change under an application that never asked for it.
     * So the drop happens only on an EMPTY table (the disposable-database case
     * this is actually for), and a populated one stops the rollback with an
     * explanation instead. Nothing is lost either way, and the ledger no longer
     * desynchronises silently.
     *
     * ## What a drop does NOT do, when it does happen
     *
     * It removes the ROWS, not the FILES. Every media row points at a blob on a
     * configured disk (`disk` / `conversions_disk` + `file_name`), and Spatie
     * only removes those blobs through the model's deleting event. A schema
     * rollback bypasses Eloquent entirely, so storage directories survive while
     * the index of them is destroyed — orphans nothing in the app can
     * enumerate. Purge the disk deliberately BEFORE a rollback, not after.
     *
     * `dropIfExists` rather than `drop`: the media table is also created by
     * spatie/laravel-medialibrary's own published migration, so a consumer app
     * can reach this down() with the table already gone.
     */
    public function down(): void
    {
        if (Schema::hasTable('media') && DB::table('media')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back [media]: the table still holds rows, and dropping it would '
                .'destroy the only index of the files on disk while leaving those files behind. '
                .'Delete the media through the application (which removes the blobs too), or drop '
                .'the table by hand once you have decided what happens to its storage, then run '
                .'the rollback again.'
            );
        }

        Schema::dropIfExists('media');
    }
};
