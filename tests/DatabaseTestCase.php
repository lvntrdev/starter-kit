<?php

namespace Lvntr\StarterKit\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Tests\Stubs\TestFileFavorite;
use Lvntr\StarterKit\Tests\Stubs\TestFileFolder;
use Lvntr\StarterKit\Tests\Stubs\TestMedia;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

/**
 * DB-aware TestCase: SQLite in-memory veritabanı ile çalışır.
 *
 * Hangi tabloları oluşturduğu:
 *   - settings                         (stub migration'dan alınan şema)
 *   - users                            (file_manager_share_revocations FK için)
 *   - media                            (Spatie stub + deleted_at soft-delete kolonu)
 *   - global_file_buckets              (vendor migration)
 *   - file_folders                     (vendor migration)
 *   - file_favorites                   (vendor migration)
 *   - file_manager_share_revocations   (vendor migration — T4 share feature)
 *
 * Tablolar Schema builder ile inline oluşturulur (loadMigrationsFrom yerine).
 * Bu yaklaşım Testbench-core'un kendi fixture migration'larının (dedupe_receipts
 * vb.) migrate:fresh sırasında yanlışlıkla çalışmasını engeller.
 *
 * media-library config'inde media_model olarak
 * Lvntr\StarterKit\Tests\Stubs\TestMedia bind edilir; bu model
 * Spatie base Media'ya SoftDeletes ekler — consumer'ın stub Media
 * modelini taklit eder, App namespace gerektirmez.
 */
abstract class DatabaseTestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * artisan migrate:fresh yerine doğrudan Schema builder ile tabloları kurar.
     *
     * RefreshDatabase::migrateDatabases() normalde 'artisan migrate:fresh'
     * çalıştırır; Testbench-core 'workbench.install = true' (default) ile
     * kendi fixture migration dizinini (duplicate dedupe_receipts içeren)
     * migrator'a ekliyor ve hata üretir.
     *
     * Bu override: fresh çalıştırmadan önce mevcut tabloları drop eder
     * (IN MEMORY SQLite — her test process yeni connection açmadığından
     * RefreshDatabase tabloları test arası rollback ile yönetir, ancak
     * migrate:fresh yerine Schema::drop kullanmak daha güvenli).
     */
    protected function migrateDatabases(): void
    {
        // SQLite in-memory: her test RefreshDatabase ile transaction
        // rollback yapıyor, bu yüzden burada drop gerekmez.
        // Ancak ilk çalıştırmada tablolar yoktur.
        // Schema builder idempotent değildir — "table already exists" hatasını
        // önlemek için mevcut tabloları önce drop et.
        Schema::dropIfExists('file_manager_share_revocations');
        Schema::dropIfExists('file_favorites');
        Schema::dropIfExists('file_folders');
        Schema::dropIfExists('global_file_buckets');
        Schema::dropIfExists('media');
        Schema::dropIfExists('users');
        Schema::dropIfExists('settings');

        // Ardından tabloları sıfırdan oluştur.
        $this->defineDatabaseMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StarterKitServiceProvider::class,
            MediaLibraryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in-memory
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Test Media modeli — App namespace gerektirmez
        $app['config']->set('media-library.media_model', TestMedia::class);

        // Test stub model binding'leri — App\Models\* namespace gerektirmez
        $app['config']->set('file-manager.models.folder', TestFileFolder::class);
        $app['config']->set('file-manager.models.favorite', TestFileFavorite::class);

        // file-manager config defaults
        $app['config']->set('file-manager.settings.storage_quota_gb', 10);
        $app['config']->set('file-manager.settings.max_size_mb', 10);

        // MediaLibrary disk
        $app['config']->set('media-library.disk_name', 'public');
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
        ]);

        // Encryption key (Cache/session ihtiyacı için)
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // StarterKitServiceProvider::registerMigrations() kapatılır.
        // Tablolar defineDatabaseMigrations() içinde Schema builder ile
        // inline oluşturulacak — çift migration çakışmasını önler.
        $app['config']->set('starter-kit.run_migrations', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Tablolar Schema builder ile inline oluşturulur.
        // loadMigrationsFrom() kullanılmaz — bu yaklaşım Testbench-core'un
        // fixture migration'larının (dedupe_receipts vb.) migrate:fresh
        // sırasında çalışmasını önler.
        //
        // ┌─ ŞEMA KOPYASI — DEĞİŞTİRİRSEN İKİSİNİ BİRDEN GÜNCELLE ─────────────┐
        // │ Aşağıdaki tablolar şu package migration'larının birebir kopyasıdır. │
        // │ Bir migration'a kolon ekler/çıkarırsan buradaki inline bloğu da    │
        // │ güncelle — aksi halde tests/Feature/SchemaDrift/InlineSchemaDrift-  │
        // │ Test.php kolon seti sapmasını yakalar ve KIRMIZI yanar.            │
        // │                                                                    │
        // │   settings                       → database/migrations/            │
        // │       2026_03_14_080933_create_settings_table.php                  │
        // │   media                          → database/migrations/            │
        // │       2026_03_08_205445_create_media_table.php                     │
        // │       2026_04_13_100200_add_folder_id_to_media_table.php           │
        // │       2026_05_02_094121_add_soft_deletes_to_media_table.php        │
        // │   global_file_buckets            → database/migrations/            │
        // │       2026_04_13_100000_create_global_file_buckets_table.php       │
        // │   file_folders                   → database/migrations/            │
        // │       2026_04_13_100100_create_file_folders_table.php              │
        // │   file_favorites                 → database/migrations/            │
        // │       2026_05_02_092853_create_file_favorites_table.php            │
        // │   file_manager_share_revocations → database/migrations/            │
        // │       2026_05_06_100000_create_file_manager_share_revocations…php   │
        // │                                                                    │
        // │ İSTİSNA: `users` bir migration KOPYASI DEĞİL — kasıtlı minimal     │
        // │ shim'dir (gerçek stub users migration'ı UUID-anahtarlı ve çok daha │
        // │ geniştir). Yalnız FK/testlerin ihtiyaç duyduğu kolonları taşır;    │
        // │ drift testi onu kolon-seti karşılaştırmasından hariç tutar, yalnız │
        // │ kritik kolonların (id / email / password / password_changed_at /   │
        // │ timezone) varlığını doğrular. Yalnız TESPİT eklenir — test DB      │
        // │ kurulumu migration koşumuna çevrilmez (Testbench dedupe_receipts   │
        // │ sorunu, bkz. yukarı).                                              │
        // └────────────────────────────────────────────────────────────────────┘

        // 1. settings tablosu
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group')->index();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        // 2. users tablosu — file_manager_share_revocations.revoked_by_user_id
        //    ve file_folders.created_by FK'leri için gerekli.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // UserDatatableQuery::searchable() / UserBulkSelectionQuery search
            // these two as well — present (nullable) so the shipped search
            // predicate can run against this table in parity tests.
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            // Kullanıcı bazlı görüntüleme timezone'u. `null` anlamlıdır:
            // "site ayarını izle" demektir, 'UTC' seçmekle aynı DEĞİLDİR.
            $table->string('timezone', 64)->nullable();
            $table->string('password');
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamps();
        });

        // 3. media tablosu (Spatie stub şeması + deleted_at soft-delete kolonu)
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
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
            $table->string('folder_id')->nullable()->index(); // FileManager extension
            $table->nullableTimestamps();
            $table->softDeletes(); // consumer Media modeli SoftDeletes kullanıyor
        });

        // 4. global_file_buckets tablosu
        Schema::create('global_file_buckets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->default('default');
            $table->timestamps();
        });

        // 5. file_folders tablosu
        Schema::create('file_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('owner_type')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'owner_id', 'parent_id', 'name'], 'file_folders_owner_parent_name_unique');

            $table->foreign('parent_id')->references('id')->on('file_folders')->cascadeOnDelete();
        });

        // 6. file_favorites tablosu
        Schema::create('file_favorites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('favoritable_type', 16); // 'folder' | 'file'
            $table->string('favoritable_id'); // folder UUID or media integer id (stored as string)
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['owner_type', 'owner_id', 'favoritable_type', 'favoritable_id'],
                'file_favorites_unique',
            );
            $table->index(['owner_type', 'owner_id'], 'file_favorites_owner_idx');
        });

        // 7. file_manager_share_revocations tablosu (T4 share feature — K2 fix ile)
        Schema::create('file_manager_share_revocations', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('media_id');
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            // K2 (security): Composite unique (media_id, signed_token_hash)
            $table->string('signed_token_hash', 64);
            $table->unique(['media_id', 'signed_token_hash'], 'fm_share_rev_media_token_unique');
            $table->timestamp('revoked_at');
            $table->uuid('revoked_by_user_id')->nullable();
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }
}
