<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable `deleted_at` column to the Spatie media table so the
     * FileManager trash feature can soft-delete files. The column is nullable
     * with a null default, which preserves Spatie's default behaviour for any
     * code path that does not opt into the SoftDeletes trait.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }

    /**
     * Refuse while the table still holds rows, exactly as create_media_table's
     * own down() does.
     *
     * This is the NEWEST migration in the media chain, so a rollback reaches it
     * FIRST — before the folder-id drop and long before the create migration's
     * refusal. Without the guard here, `deleted_at` is gone by the time
     * anything objects: every trashed file silently becomes a live one again,
     * and the FileManager trash it belonged to cannot be reconstructed.
     */
    public function down(): void
    {
        if (Schema::hasTable('media') && DB::table('media')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back [media.deleted_at]: the table still holds rows, and dropping '
                .'the column would turn every trashed file back into a live one with no record of '
                .'what was in the trash. Delete the media through the application first, then run '
                .'the rollback again.'
            );
        }

        Schema::table('media', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
