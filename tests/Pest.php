<?php

use Lvntr\StarterKit\Tests\DatabaseTestCase;
use Lvntr\StarterKit\Tests\MigrationTestCase;
use Lvntr\StarterKit\Tests\PermissionMiddlewareTestCase;
use Lvntr\StarterKit\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest test bootstrap
|--------------------------------------------------------------------------
|
| Feature suite'e TestCase bağlanır. Unit testler (eklenirse) Pest default
| base class'ı kullanır — Laravel boot gerekmez.
|
| Feature/FileManager ve Feature/Settings testleri DatabaseTestCase ile
| çalışır: SQLite in-memory + migration + RefreshDatabase sağlar.
| Diğer Feature testleri (BackwardCompat) DB gerektirmediğinden
| hafif TestCase'i kullanmaya devam eder.
|
*/

// DB gerektiren test dizinleri
uses(DatabaseTestCase::class)->in('Feature/SchemaDrift');
uses(DatabaseTestCase::class)->in('Feature/FileManager');
uses(DatabaseTestCase::class)->in('Feature/Settings');

// Paket migration zincirinin GERÇEK sürücü üzerinde koşturulması: SQLite'ta
// (varsayılan `composer test`) bütün dizin kendini skip eder, MySQL/MariaDB'de
// stubs + vendor migration'larını tek batch halinde uygular. Tabloları inline
// kuran DatabaseTestCase'ten kasıtlı olarak ayrı — bkz. MigrationTestCase.
uses(MigrationTestCase::class)->in('Feature/Migration');

// Rollback-path guard: her migration dosyasının down() taşıdığını STATİK olarak
// (dosyayı okuyup reflection ile) doğrular. Feature/Migration'a KOYULAMAZ —
// orası MigrationTestCase'e bağlı ve SQLite'ta kendini skip eder; guard'ın
// değeri ise varsayılan `composer test` koşusunda çalışmasında. Pest bir dosyayı
// tek base class'a bağladığı için ayrı dizin gerekiyor. DB gerektirmez.
uses(TestCase::class)->in('Feature/MigrationSafety');

// Logs audit listener regresyonu: activity('system') yazımı için DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/Logs');

// Datatable query builder testleri: users tablosu üzerinden DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/Datatable');

// ActionPipeline transaction/rollback testleri: settings tablosuna gerçek
// yazım + rollback doğrulaması için DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/Shared');

// User model/request/profile/shared-prop tests use the inline users table.
uses(DatabaseTestCase::class)->in('Feature/User');

// Cross-page bulk selection scope/allow-list testleri: roles + pivot şeması
// inline kurulur, users tablosu üzerinden DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/BulkSelection');

// İçerik dilleri (content languages) testleri: content_languages tablosu
// beforeEach'te inline kurulur, Action invariant + validation + gating DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/ContentLanguage');

// Definition cache invalidation testleri: definitions tablosu beforeEach'te
// inline kurulur, model-event flush + tek-anahtar okuma DB gerekli.
uses(DatabaseTestCase::class)->in('Feature/Definition');

// CheckResourcePermission middleware testleri: Spatie permission tabloları +
// PermissionServiceProvider gerekli (route-name → permission türetme, prod deny /
// non-prod warn, sub-resource çözümü).
uses(PermissionMiddlewareTestCase::class)->in('Feature/Middleware');

// Exception handler mapping testleri: saf resolve() eşlemesi, DB gerektirmiyor
uses(TestCase::class)->in('Feature/Exceptions');

// EnsureUserIsActive testleri: middleware kendi rotalarını tanımlar ve
// kullanıcıyı DB'ye yazmadan guard'a set eder (actingAs), DB gerektirmiyor.
uses(TestCase::class)->in('Feature/Auth');

// DB gerektirmeyen diğer Feature testleri
uses(TestCase::class)->in('Feature/BackwardCompat');

// Data-at-rest şifreleme testleri (DataEncrypterFactory anahtar zinciri +
// container/Fortify wiring): anahtar çözümü saf bir config fonksiyonudur, DB
// gerektirmez. NOT: Pest bir dosyayı yalnız TEK bir base class'a bağlar — aynı
// dosya iki uses() hedefine düşerse TestCaseAlreadyInUse fırlatır; bu yüzden
// DB isteyen şifreleme regresyonu kendi tablolarını inline kurar.
uses(TestCase::class)->in('Feature/Encryption');

// Doctor testleri: DB gerektirmiyor, basit TestCase yeterli
uses(TestCase::class)->in('Feature/Doctor');

// Security header testleri: middleware doğrudan handle() ile çağrılır, DB gerektirmiyor
uses(TestCase::class)->in('Feature/Security');

// Bulk action testleri: DB gerektirmiyor, mock tabanlı
uses(TestCase::class)->in('Feature/Bulk');

// Generator testleri: DB gerektirmiyor, basit TestCase yeterli
uses(TestCase::class)->in('Feature/Generator');

// Eject testleri: --destination ile temp dizine yazar, DB gerektirmiyor
uses(TestCase::class)->in('Feature/Eject');

// Install testleri: pure unit (isolated string/reflection), DB gerektirmiyor
uses(TestCase::class)->in('Feature/Install');

// VendorFirst testleri: Task 6 — install/update/eject/alias-bridge senaryoları, DB gerektirmiyor
uses(TestCase::class)->in('Feature/VendorFirst');

// EnvSync testleri: gerçek dosya sistemi (.env / .env.example) üzerinde çalışır, DB gerektirmiyor
uses(TestCase::class)->in('Feature/EnvSync');
