# API Rotaları Admin Modülü

ApiRoutes modülü, admin panel içinde uygulamanın API ve servis route yüzeyini görünür kılar. Özellikle API tüketen ekipler, entegrasyon yapan geliştiriciler ve operasyon tarafı için mevcut uçları tek ekranda incelemeyi kolaylaştırır.

## Ne İşe Yarar

- API endpoint'lerini panel içinden listeler
- servis route'larını ayrı bölümde gösterir
- HTTP method, URI, route adı, action ve middleware bilgisini görünür kılar
- api-dock OpenAPI dokümanını panel içinden yeniden üretir
- `/api-dock` dokümantasyon paneline hızlı erişim sunar
- mevcut OpenAPI spec'ini Postman'e taze bir koleksiyon olarak gönderir
- aynı spec'i Apidog'daki hedef projeye yazar (üzerine yazar)

## Route'lar

Modül şu web route'larını kullanır:

| Method | Yol | Route adı | Amaç |
| --- | --- | --- | --- |
| `GET` | `/api-routes` | `api-routes.index` | API ve servis route listesini gösterir |
| `POST` | `/api-routes/regenerate-docs` | `api-routes.regenerateDocs` | API dökümantasyonunu yeniden üretir |
| `POST` | `/api-routes/postman-sync` | `api-routes.syncPostman` | Güncel OpenAPI spec'ini Postman'e gönderir |
| `POST` | `/api-routes/apidog-sync` | `api-routes.syncApidog` | Güncel OpenAPI spec'ini Apidog'a gönderir |

Tanımlar için [routes/web/developer-route.php](../stubs/routes/web/developer-route.php) dosyasına bakın.

## Ekran Davranışı

`resources/js/pages/Admin/ApiRoutes/Index.vue` iki ana tablo render eder:

- **API Endpoints**: `/api/v1` yüzeyindeki endpoint'ler
- **Service Endpoints**: panel içinde kullanılan servis route'ları

Her kayıt için şu bilgiler gösterilir:

- HTTP method
- URI
- route name
- controller action
- middleware listesi

Sayfanın üst kısmındaki aksiyonlar:

- **Regenerate Docs**: api-dock OpenAPI dokümanını yeniden üretir ve `config('api-dock.ai.export_path')` altındaki `admin/` alt dizinine yazar — varsayılan olarak `storage/api-dock/admin/openapi.json`. Export köküne bilerek yazmaz: o yol aynı zamanda `api-dock:diff` / `api-dock:sync --check` komutlarının kıyasladığı varsayılan `api-dock.snapshot.path`'tir; panelden üzerine yazmak CI referansını sessizce güncellerdi.
- **Open API Docs**: api-dock panelini yeni sekmede açar. URL sunucu tarafında `api-dock.docs` isimli route'undan çözülür, böylece özelleştirilmiş `api-dock.route_prefix` de çalışır; api-dock yoksa veya kapalıysa buton hiç render edilmez.

## Backend Yapısı

- Controller: `app/Http/Controllers/Admin/ApiRouteController.php` (uygulamanıza scaffold edilir)
- Query: `Lvntr\StarterKit\Domain\ApiRoute\Queries\ApiRouteListQuery` (vendor-resident, `src/Domain/ApiRoute/`)
- Action: `Lvntr\StarterKit\Domain\ApiRoute\Actions\RegenerateApiDocsAction` (vendor-resident, `src/Domain/ApiRoute/`)

ApiRoute runtime katmanı paket içinden çalışır; `App\Domain\ApiRoute\...` import'ları `class_alias` ile çalışmaya devam eder.

Controller, liste ekranını Inertia ile render eder; yeniden üretme işlemini ise standart `ApiResponse` zarfı ile döner.

## Yetki ve Erişim

Bu ekran authenticated admin route grubunda çalışır ve `check.permission` middleware'inden geçer. Route adı `api-routes.index` olduğu için erişim kuralı, projenin permission çözümleme mantığına göre belirlenir.

Projede ayrıca `api-docs.read` gibi ilgili permission girdileri de bulunur. Roller ve yetkiler tarafı için [roles-permissions.tr.md](./roles-permissions.tr.md) dosyasına bakın.

## API İstemci Senkronizasyonu

Admin ekranındaki araç çubuğu, **Regenerate Docs** butonunun yanına iki yeni aksiyon daha ekler:

- **Sync to Postman**: `SyncPostmanAction` çalışır; güncel OpenAPI dokümanını api-dock'un `DocumentGenerator`'ı üzerinden üretir ve Postman'in `POST /import/openapi` uçuna `folderStrategy=Tags` parametresiyle yükler. Her sync önce taze koleksiyonu import eder, yeni UID'yi ayarlara yazar, sonra eski koleksiyonu best-effort siler — `import-first, delete-after` sırası sayesinde Postman tarafında geçici bir hata mevcut çalışan koleksiyonu kaybettirmez.
- **Sync to Apidog**: `SyncApidogAction` çalışır; aynı dokümanı Apidog'un `POST /v1/projects/{id}/import-openapi` uçuna inline JSON olarak `OVERWRITE_EXISTING` modunda gönderir.

Her iki buton da ortak bir loading spinner ve işlem sonucunu bildiren bir toast gösterir. İlgili kimlik bilgileri eksikse ilgili buton devre dışı kalır ve bir yönlendirme ipucu kullanıcıyı **Settings → API Clients** ekranına götürür — `postman` ve `apidog` settings grupları burada yönetilir. Gizli alanlar (`postman.api_key`, `apidog.access_token`) [config/settings.php](../stubs/config/settings.php) içindeki `sensitive_keys` listesi üzerinden şifrelenerek saklanır.

İki Action ortak bir yardımcıyı, `Lvntr\StarterKit\Domain\ApiRoute\Support\OpenApiExporter` sınıfını (vendor-resident, `src/Domain/ApiRoute/`) kullanır: bu sınıf api-dock'un `DocumentGenerator`'ını container'dan çözer — `/api-dock` paneli ve her `api-dock:*` konsol komutu da aynı giriş noktasını kullanır, dolayısıyla Postman/Apidog'a gönderilen doküman panelin gösterdiğiyle bayt bayt aynıdır. Doküman hedef istemciye **değiştirilmeden** iletilir; content-type rewrite'ı bilinçli olarak yapılmaz, böylece gönderilen koleksiyon gerçek sunucu kontratını aynen yansıtır.

Aynı akışlar CLI'dan da kullanılabilir (CI senaryoları için faydalıdır):

    php artisan postman:sync
    php artisan apidog:sync

Komutlar aynı Action sınıflarını çağırdığı için kimlik bilgisi ve yetki kuralları UI ile aynıdır.

## Ne Zaman Kullanılmalı

- projedeki mevcut API yüzeyini panelden hızlıca görmek istediğinizde
- yeni entegrasyon öncesi route ve middleware kontrolü yapmak istediğinizde
- API dokümantasyonu güncellendikten sonra export'u yeniden üretmek istediğinizde
- destek veya geliştirme sırasında hangi endpoint'in hangi action'a gittiğini görmek istediğinizde
