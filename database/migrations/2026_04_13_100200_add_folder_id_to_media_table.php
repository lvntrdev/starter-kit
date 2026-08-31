<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->uuid('folder_id')->nullable()->after('collection_name')->index();

            $table->foreign('folder_id')->references('id')->on('file_folders')->nullOnDelete();
        });
    }

    /**
     * Refuse while the table still holds rows, exactly as create_media_table's
     * own down() does.
     *
     * The guard has to live HERE too, not only on the create migration. A
     * rollback walks a batch newest-first, so this down() and the soft-delete
     * one both run BEFORE the create migration's refusal is ever reached — the
     * folder assignment of every stored file would already be gone by the time
     * anything said no. Dropping this column loses which folder each file sits
     * in, and nothing in the schema can reconstruct it.
     */
    public function down(): void
    {
        if (Schema::hasTable('media') && DB::table('media')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back [media.folder_id]: the table still holds rows, and dropping '
                .'the column would discard the folder each stored file belongs to with nothing left '
                .'to rebuild it from. Delete the media through the application first, then run the '
                .'rollback again.'
            );
        }

        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });
    }
};
