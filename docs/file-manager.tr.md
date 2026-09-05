# Dosya Yöneticisi

Yeniden kullanılabilir, Windows Explorer tarzı dosya yönetim UI'ı. Her Eloquent model'i kendi klasör ağacına sahip olabilir — iki context built-in gelir (`user`, `global`); yeni context'ler (`vehicle`, `okul`, `proje`, …) [ContextRegistry](#özel-custom-contextler) ile component'e dokunmadan eklenir.

Spatie MediaLibrary üstüne mantıksal klasör katmanıyla kurulu — klasör/dosya taşımaları yalnızca DB güncellemesidir (fiziksel dosya hareketi yok).

## Temel Yetenekler

- hızlı erişim listesi (Tüm Dosyalar, Son Yüklenenler, Favoriler, Çöp Kutusu), klasör ağacı ve dairesel storage-kullanım halkasıyla sidebar
- mevcut klasörü client-side filtreleyen üst-bar arama
- üst stats widget'ı (Toplam Dosya, Toplam Boyut, Klasör Sayısı, Favoriler, Son Yükleme)
- grid + breadcrumb üzerinden nested klasör gezintisi
- klasör oluşturma, yeniden adlandırma, silme (cascade)
- klasör/dosya favorileme ve Favoriler hızlı görünümü
- geri yükleme, kalıcı silme ve Çöpü Boşalt aksiyonlarıyla soft-delete Çöp Kutusu akışı
- dosya kopyalama ve dosya yeniden adlandırma
- tile bazlı ilerleme çubuğuyla dosya yükleme
- klasörler arasında sürükle-bırak ile taşıma
- toplu silme
- indirme ile birlikte tam ekran görsel lightbox'ı / inline önizleme modalı
- dosya detayları dialog'u (ad, tip, boyut, yüklenme tarihi, klasör, resim boyutları)
- panoya bağlantı paylaşımı
- klavye kısayolları (`Ctrl+A`, `Delete`, `Esc`, `Enter`)

## Import

```ts
import FileManager from '@lvntr/components/FileManager/FileManager.vue';
```

## Temel Kullanım

### Kullanıcı (user) bağlamı — kullanıcı başına dosyalar

```vue
<FileManager context="user" :context-id="user.id" height="100%" />
```

### Global bağlam — sistem genelindeki dosyalar

```vue
<FileManager context="global" height="100%" />
```

### Özel (custom) bağlam — herhangi bir Eloquent model

```vue
<FileManager context="vehicle" :context-id="vehicle.id" height="100%" />
```

Buradaki `vehicle` ya bir morph-map alias'ı, ya convention gereği `App\Models\Vehicle`'a çözen bir isim, ya da `ContextRegistry::register()` ile açıkça kaydedilmiş bir anahtar. Çözüm sırası için [Özel (custom) context'ler](#özel-custom-contextler) bölümüne bakın.

## Props

| Prop            | Tip                            | Varsayılan | Açıklama                                                                      |
| --------------- | ------------------------------ | ---------- | ----------------------------------------------------------------------------- |
| `context`       | `'user' \| 'global' \| string` | zorunlu    | `ContextRegistry`'e kayıtlı context anahtarı (built-in: `user`, `global`)     |
| `contextId`     | `string \| null`               | `null`     | Owner primary key — path şablonu `{id}` içeren context'lerde zorunlu          |
| `readonly`      | `boolean`                      | `false`    | Tüm mutasyonları devre dışı bırakır (yükleme/silme/yeniden adlandırma/taşıma) |
| `enableTrash`   | `boolean`                      | config     | Silmeleri Çöp Kutusu'na taşır; `config('file-manager.settings.enable_trash')` değerine (varsayılan `true`) geri döner. Prop açıkça geçilirse config değerini ezer. |
| `acceptedMimes` | `string[]?`                    | settings   | Kabul edilen MIME listesini override eder                                     |
| `maxSizeKb`     | `number?`                      | settings   | Maksimum yükleme boyutunu override eder                                       |
| `height`        | `string`                       | `'600px'`  | Shell'in CSS yüksekliği; flex parent'ı doldurmak için `100%`                  |

## Layout

Kabuk üç bölgeli bir kolondur:

1. **Üst bar** — mevcut klasörün dosya ve klasörlerini ada göre client-side filtreleyen tek satırlık arama kutusu (`IconField` + `InputText`).
2. **Body** — flex satır olarak ikiye ayrılır:
    - **Sidebar** (`FileManagerSidebar`) — dairesel storage-kullanım halkası, hızlı-erişim listesi, klasör ağacı, "Yeni Klasör" butonu.
    - **Ana kolon** — stats widget'ı (`FileManagerStats`) + breadcrumb + tile grid'i.

`height` prop'u kolon yüksekliğini kontrol eder; sidebar ve ana kolon o yükseklik içinde kalan alanı paylaşır.

## Özellikler

### Sidebar (`FileManagerSidebar`)

- **Storage kullanım halkası** — `usedBytes / quotaBytes`'ı yüzde olarak gösteren SVG çember. Renk bandı: primary < 70 %, amber 70–90 %, rose ≥ 90 %. Kullanılan byte'lar `fm.contents.stats.total_size`'tan gelir. Kota şimdilik backend setting'i bağlanana kadar görsel olarak makul 10 GB default'tur; değer değiştiğinde halka doğru ölçeklenmeye devam eder.
- **Hızlı erişim** — dört giriş:
    - **Tüm Dosyalar** — root klasör, ada göre asc sıralı.
    - **Son Yüklenenler** — root klasör, tarihe göre desc sıralı.
    - **Favoriler** — `file_favorites` tablosuyla beslenen sanal görünüm.
    - **Çöp Kutusu** — soft-delete edilmiş klasörler ve File Manager media kayıtlarının sanal görünümü; `enableTrash=false` iken gizlenir.
- **Klasör ağacı** — taşıma modalı'nın zaten yüklediği aynı nested veri (`fm.tree`); bir node'a tıklamak grid üzerinden o klasöre gitmekle eşdeğerdir.
- **Yeni Klasör** — boş-durum "Yeni Klasör" ipucuyla aynı oluşturma dialog'unu açan inline buton.

### Stats widget'ı (`FileManagerStats`)

Breadcrumb'ın üzerinde icon-tinted kartlardan oluşan yatay sıra:

| Kart          | Kaynak                                                                                          |
| ------------- | ----------------------------------------------------------------------------------------------- |
| Toplam Dosya  | `fm.contents.stats.file_count`                                                                  |
| Toplam Boyut  | `fm.contents.stats.total_size` (insan-okur formatta)                                            |
| Klasör Sayısı | tüm nested ağaç (`flattenTree(fm.tree.value).length`)                                           |
| Favoriler     | File Manager stats prop'undan render edilir; hızlı görsel sayaç olarak kullanılır               |
| Son Yükleme   | mevcut klasördeki en yeni `created_at`; "Az önce / X dk / X sa / X g / locale-tarih" formatında |

### Arama

Üst-bar arama kutusu yalnızca **mevcut klasörün** render edilen tile'larını filtreler; başka bir klasöre gitmek `fm.loadContents()`'in bir sonraki çağrısında filtreyi örtük olarak sıfırlar. Filtre `folder.name` / `file.file_name` üzerinde case-insensitive `includes`'tur.

### Tile'lar ve seçim

- **Seçim** — her tile'ın sağ üstünde her zaman görünür checkbox (seçiliyken primary-dolu, boşken hover'da outline):
    - Klasör tile'ı tek tık → tekil seçim; `çift tık` → aç
    - Dosya tile'ı tek tık → resimlerde lightbox, diğer önizlenebilir tiplerde preview modalı
    - `Ctrl/Cmd + tık` (ikisinde de) → öğeyi seçime ekle/çıkar
    - Boş alana drag → rubber-band seçim
    - Boş alana sağ tık → **Tümünü Seç**
    - Tile'a sağ tık mevcut seçimi bozmaz
- **Türe göre önizleme** — resim küçük görselleri + PDF / Word / Excel / Video / Ses / Arşiv / Metin için renk kodlu simgeler.
- **Boş klasör** — outline folder illüstrasyonu + "Yükle" / "Yeni Klasör" ipuçları.

### Önizleme akışı

- Dosyaya tıklamak (veya sağ tık **Önizle**) resimlerde tam ekran `ImageLightbox` açar; PDF, video, ses, metin ve diğer önizlenebilir resim olmayan tiplerde 90vw'lik preview dialog'u kullanılır. Tanınmayan tiplerde **Yeni sekmede aç** + **İndir** fallback'i vardır.
- Dosya context menüsündeki ayrı **Aç** girişi artık MIME tipinden bağımsız olarak dosyayı yeni bir tarayıcı sekmesinde (`noopener,noreferrer`) açar; **Önizle** ile yan yana çalışır.

### Yükleme

- **Tile bazlı upload progress'i** — her dosya ayrı XHR ile stream ediliyor; her bırakılan/seçilen dosya grid'de optimistic placeholder tile olarak anında çıkıyor, üstünde dolan progress bar var. Hatalı yüklemeler dismissable error tile olarak kalıyor; başarılılar liste yenilendiğinde gerçek dosyayla yer değiştiriyor.
- **Tüm alana external drop zone** — OS dosyalarını FileManager yüzeyinde herhangi bir yere sürükleyince upload overlay'i çıkıyor. Internal tile drag'leri tetiklemiyor (`Files` data-transfer tipi ile ayırt ediliyor).

### Favoriler

- Çöp görünümü dışında her klasör/dosya tile'ının sol üstünde yıldız toggle'ı vardır.
- Klasör ve dosya context menüleri de Favorilere Ekle / Favorilerden Çıkar aksiyonlarını gösterir.
- Favoriler dosya ve klasörlerle aynı context owner'a scope edilir; bir kullanıcının favorileri başka kullanıcı/global/custom context'e sızmaz.
- Favoriler hızlı görünümü sanaldır: mevcut context'teki favori klasör ve dosyaları listeler, subtree istatistiği toplamaz.

### Çöp Kutusu, geri yükleme ve kalıcı silme

- `enableTrash=true` (varsayılan) iken dosya veya klasör silmek öğeyi soft-delete eder ve Çöp Kutusu hızlı görünümünde gösterir.
- Çöp Kutusu içindeyken context menüler **Geri Yükle** ve **Kalıcı Olarak Sil** aksiyonlarına döner. Normal aç/taşı/favori aksiyonları gizlenir.
- Bir klasörü geri yüklemek, alt klasörlerini ve File Manager media kayıtlarını transaction içinde geri yükler. Parent klasör hâlâ çöpteyse önce parent geri yüklenene kadar işlem reddedilir; parent kalıcı olarak silinmişse öğe root'a geri yüklenir.
- **Çöpü Boşalt**, mevcut context'teki tüm çöp öğelerini kalıcı olarak siler. Dosyalar klasörlerden önce, klasörler çocuklar önce olacak sırayla silinir.
- `php artisan file-manager:purge-trash --days=7`, belirlenen yaştan eski File Manager çöpünü kalıcı olarak siler. Paketle gelen `routes/console.php` içinde günlük schedule edilmiştir.
- Çöp Kutusu akışını atlayıp doğrudan kalıcı silme kullanmak için `:enable-trash="false"` verin. Bu modda tekil silmeler doğrudan kalıcı silme endpoint'ini çağırır; seçili öğe silmeleri ise toplu silme endpoint'ine `force_delete=true` gönderir.

### Taşıma, toplu silme, yeniden adlandırma, kopyalama, paylaşma, detaylar

- **Drag-and-drop taşıma** — tile'lar `draggable`; bir klasör tile'ına bırakıldığında seçili tüm öğeler hedef klasöre taşınıyor.
- **Move modalı** — iki context menüde de **Taşı** var; dialog'ta `FolderTree` picker (root'a taşıma dahil), tek ve çoklu kaynak destekli.
- **Toplu silme** — toolbar'da seçim olduğunda "Seçilenleri Sil" butonu veya seçili öğeye sağ tık → **Seçilenleri Sil (N)**. Çöp Kutusu açıkken aktif öğeleri soft-delete eder; Çöp Kutusu içindeyken veya `enableTrash=false` olduğunda `force_delete=true` gönderip seçili öğeleri kalıcı siler.
- **Yeniden Adlandır** — klasör ve dosya context menüleri yeniden adlandırma dialog'u açar. Aynı klasörde çakışan isimler sunucu tarafında reddedilir.
- **Kopyalama** — dosya context menüsündeki **Çoğalt**, mevcut klasörde (veya verilen hedef klasörde) `photo (copy).jpg` / `photo (copy 2).jpg` gibi çakışmasız isimle fiziksel MediaLibrary kopyası oluşturur.
- **Paylaş** — dosya context menüsündeki **Paylaş** mutlak dosya URL'sini `navigator.clipboard.writeText(...)` ile panoya kopyalar ve başarıda yerelleştirilmiş "Bağlantı kopyalandı" toast'u gösterir. Clipboard izni reddedilirse onun yerine yerelleştirilmiş "yakında geliyor" toast'u çıkar.
- **Detaylar** — dosya context menüsündeki **Detaylar** girişi Ad, Tip, Boyut, Yüklenme, Klasör ve (resimlerde) Boyutlar'ı gösteren `FileDetailsDialog`'u açar. Resim boyutları gizli bir `new Image()` ile async yüklenir. Dialog, sağ-tık menüsündeki indirme handler'ını yeniden kullanan bir **İndir** footer butonuyla gelir.
- **Busy overlay** — Sil / Taşı / Yeniden Adlandır işlemlerinde FileManager alanının üstüne modal kart (spinner + başlık) çıkıyor; toplu işlerde "N öğe kaldı" canlı sayaç + **Durdur** butonu döngüyü iptal ediyor.

### Context menüler

Klasör / dosya / boş alana sağ tık; gruplar arası separator'lar ve destructive Sil satırına özel bir `fm-menu-danger` class'ı olan yuvarlatılmış kart:

- **Klasör** — Aç, Yeniden Adlandır, Taşı, Favorilere Ekle/Çıkar, Sil.
- **Dosya** — Aç (yeni sekmede), Önizle, İndir, Paylaş, Taşı, Çoğalt, Yeniden Adlandır, Favorilere Ekle/Çıkar, Detaylar, Sil.
- **Çöp klasör/dosya** — Geri Yükle, Kalıcı Olarak Sil.
- **Boş alan** — Yeni Klasör, Yükle, Tümünü Seç, Yenile.

### Klavye

- Odaklanmış tile üzerinde `Enter` — aç
- `Ctrl/Cmd + A` — mevcut klasördeki tüm öğeleri seç
- `Delete` / `Backspace` — seçimi sil (onaylı)
- `Esc` — seçimi temizle
- Input içinde yazarken veya dialog açıkken tüm kısayollar engellenir.

## Route Yüzeyi

Tüm uçlar `context` ve `context_id` parametrelerini GET/DELETE'te query string olarak, POST/PATCH'te body'de alır.

| Method | Yol                                                  | Amaç                                                                  |
| ------ | ---------------------------------------------------- | --------------------------------------------------------------------- |
| GET    | `/file-manager/tree`                                 | Bağlamın tüm nested klasör ağacı                                      |
| GET    | `/file-manager/contents?folder_id=&sort=&direction=` | Klasör içeriği + istatistik                                           |
| GET    | `/file-manager/favorites/contents`                   | Context'in favori klasör/dosyaları                                    |
| POST   | `/file-manager/favorites`                            | Klasör/dosyayı favorilere ekle (`item_type`, `item_id`)               |
| DELETE | `/file-manager/favorites`                            | Klasör/dosyayı favorilerden çıkar (`item_type`, `item_id`)            |
| GET    | `/file-manager/trash/contents`                       | Context'in soft-delete edilmiş klasör/dosyaları                       |
| DELETE | `/file-manager/trash/empty`                          | Context'teki tüm çöp öğelerini kalıcı sil                             |
| POST   | `/file-manager/items/restore`                        | Çöpteki tek klasör/dosyayı geri yükle (`item_type`, `item_id`)        |
| DELETE | `/file-manager/items/permanent`                      | Aktif veya çöpteki tek klasör/dosyayı kalıcı sil (`item_type`, `item_id`) |
| POST   | `/file-manager/folders`                              | Klasör oluştur (`parent_id`, `name`)                                  |
| PATCH  | `/file-manager/folders/{folder}`                     | Yeniden adlandır (`name`)                                             |
| DELETE | `/file-manager/folders/{folder}`                     | Cascade silme (alt klasörler + media)                                 |
| PATCH  | `/file-manager/items/move`                           | Klasör veya dosya taşıma (`item_type`, `item_id`, `target_folder_id`) |
| POST   | `/file-manager/items/bulk-delete`                    | Toplu silme (`items: [{type, id}]`, opsiyonel `force_delete=true`)    |
| POST   | `/file-manager/files`                                | Multipart upload, `throttle:30,1`                                     |
| PATCH  | `/file-manager/files/{media}`                        | Dosya yeniden adlandır (`name`)                                       |
| POST   | `/file-manager/files/{media}/copy`                   | Dosya çoğalt (`target_folder_id`)                                     |
| DELETE | `/file-manager/files/{media}`                        | Tekli dosya silme                                                     |
| GET    | `/file-manager/files/{media}/download`               | Zorla indirme                                                         |
| POST   | `/file-manager/share`                                | HMAC imzalı paylaşım bağlantısı oluştur (`media_id`, `expires_in_hours?`)   |
| POST   | `/file-manager/share/revoke`                         | Paylaşım bağlantısını iptal et (`token`)                              |
| GET    | `/file-manager/share/{media}?expires=&signature=`    | İmzayı doğrula ve dosyaya erişim ver                                  |

### Upload parametreleri

`POST /file-manager/files` dosyanın kendisine ek olarak şu multipart alanlarını kabul eder:

| Alan          | Tip           | Zorunlu        | Amaç                                                                          |
| ------------- | ------------- | -------------- | ----------------------------------------------------------------------------- |
| `file`        | binary        | evet           | Yüklenen dosya                                                                |
| `context`     | string        | evet           | Context anahtarı (`user`, `global` veya kayıtlı özel anahtar)                 |
| `context_id`  | string        | context'e göre | Context path'i `{id}` içerdiğinde owner primary key                           |
| `folder_id`   | uuid          | hayır          | Context içindeki hedef klasör; root'a yüklemek için boş bırakın               |
| `folder_name` | string (≤100) | hayır          | Bu isimde root-level bir klasör varlığını garanti eder ve dosyayı içine koyar |

`folder_name` geçildiğinde `UploadFileAction::ensureManagedFolder` o isimde root-level klasörün varlığını atomik olarak garanti eder ve upload'ı içine koyar. Değer `nullable|string|max:100|regex:/^[\pL\pN _-]+$/u` ile doğrulanır — yalnızca harf, rakam, boşluk, tire, altçizgi; path-traversal ve keyfi karakterler request sınırında reddedilir. `FB.editor()` bu mekanizmayı `imageUpload.folderName` üzerinden kullanarak, her editor instance'ı için inline görsel upload'larını tek bir klasör altında gruplar.

### Client-side hata mesajları

`useFileManager` composable'ı upload hatalarını lokalize edilmiş toast mesajlarına eşler. HTTP 413 (Payload Too Large) için özel `too_large` çevirisi kullanılır; diğer tüm non-200 yanıtlarda ham status code backend mesajıyla birlikte yüzeye çıkar — böylece hata teşhisi hızlanır.

## Özel (custom) Context'ler

FileManager sadece `user` ve `global` ile sınırlı değil — her Eloquent model'i context sahibi olabilir. `ContextRegistry` bir context anahtarını şu sırayla çözer:

1. **Explicit kayıt** — `ContextRegistry::register()` (en yüksek öncelik). `global` registry'nin kendi içinde built-in olarak kayıtlı; service provider'da satır yok.
2. **Laravel morph-map alias** — anahtar `Relation::morphMap()` içindeyse, map'lenen model class'ı kullanılır.
3. **`App\Models\{Studly(key)}` convention** — örn. `context="vehicle"` → `App\Models\Vehicle` (class varsa).

Built-in `user` context tamamen auto-resolution (3. adım) + paketle gelen `UserPolicy` ile çalışır (self-access + `users.read`/`users.update` admin gate).

### Sıfır-konfig akış (service provider'a dokunmadan)

Normal bir model'e dayalı context için (`Vehicle`, `Okul`, `Proje`, …) `AppServiceProvider`'a ya da başka bir konfige dokunmanıza gerek yok. Sadece:

**1.** `App\Models\Vehicle` var olsun — veya bir morph-alias kaydedin.

**2.** `app/Policies/VehiclePolicy.php` dosyasını oluşturun (Laravel ismi ile otomatik bulur):

```php
namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function view(User $actor, Vehicle $vehicle): bool
    {
        return $actor->id === $vehicle->user_id
            || $actor->can('vehicles.read');
    }

    public function update(User $actor, Vehicle $vehicle): bool
    {
        return $actor->id === $vehicle->user_id
            || $actor->can('vehicles.update');
    }
}
```

**3.** Component'i context anahtarıyla mount edin:

```vue
<FileManager context="vehicle" :context-id="vehicle.id" height="100%" />
```

Arka planda auto-resolve edilen context `vehicle/{id}/files` path'ini kullanır. Varsayılan authorizer önce self-ownership kısa-yolunu uygular (actor'un kendi kaydı → izin — bu sayede `context="user"` ekstra konfig olmadan çalışır), değilse Laravel policy'lerine delegate eder: `read` için `$user->can('view', $vehicle)`, `create`, `update` ve `delete` için `$user->can('update', $vehicle)`. Policy yoksa Laravel varsayılan olarak reddeder; storage güvende.

> Starter kit `app/Policies/UserPolicy.php`'i hazır getirir (self + `users.read` / `users.update`), bu sayede `context="user"` kutusundan çıkar çıkmaz çalışır. Kendi context'leriniz için policy yazarken bunu şablon olarak kullanabilirsiniz.

### Ne zaman explicit register edilir

`ContextRegistry::register()`'ı sadece varsayılanlardan birini ezmek gerektiğinde kullanın — özel disk path, permission tabanlı (policy değil) auth veya built-in `global` context gibi singleton resolver:

```php
use App\Domain\FileManager\Support\ContextRegistry;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

app(ContextRegistry::class)->register('vehicle', [
    'model' => Vehicle::class,
    'path' => 'vehicles/{id}/files',   // varsayılan "vehicle/{id}/files"'i ezer
    'resolve' => fn (?string $id) => Vehicle::query()->findOrFail($id),
    'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
        $actor->can("vehicles.{$ability}"),   // permission tabanlı
]);
```

### Context definition'ının dört parçası

| Alan        | Tip                                                          | Amaç                                                                                   |
| ----------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| `model`     | `class-string<Model>`                                        | Polymorphic owner olarak saklanan Eloquent class                                       |
| `path`      | `string`                                                     | Disk path şablonu; `{id}` owner primary key'i ile değişir (singleton'lar için atlayın) |
| `resolve`   | `Closure(?string $id): Model`                                | Gelen `context_id`'den owner model'i yükleyen closure                                  |
| `authorize` | `Closure(Model $actor, string $ability, Model $owner): bool` | `$ability` → `'read'`, `'create'`, `'update'` veya `'delete'`; kit hiçbir zaman `'write'` göndermez |

Validation registry üzerinden sürer — auto-resolve de edilemeyen tanımsız anahtar 422 döner. `path` içinde `{id}` bulunan context'ler otomatik olarak `context_id` zorunlu kılar.

## Depolama Yerleşimi

`MediaPathGenerator` path şablonunu context definition'ından okur; her context klasör hareketinden bağımsız, sabit bir yerleşim kullanır:

| Context                       | Path şablonu               | Örnek                                                     |
| ----------------------------- | -------------------------- | --------------------------------------------------------- |
| `user`                        | `user/{id}/files`          | `{disk}/user/{userId}/files/{mediaUuid}/{filename}`       |
| `global`                      | `global/files`             | `{disk}/global/files/{mediaUuid}/{filename}`              |
| auto-resolve (örn. `vehicle`) | `{key}/{id}/files`         | `{disk}/vehicle/{vehicleId}/files/{mediaUuid}/{filename}` |
| custom kayıt                  | sizin belirttiğiniz şablon | şablonunuza göre                                          |

Bir dosyayı başka klasöre taşımak sadece DB'deki `media.folder_id`'yi günceller — diskteki dosya asla hareket etmez.

## Ayarlar (Admin > Ayarlar > Dosya Yöneticisi)

| Anahtar                       | Varsayılan                         | Açıklama                                  |
| ----------------------------- | ---------------------------------- | ----------------------------------------- |
| `file_manager.max_size_kb`    | `10240`                            | Dosya başına maksimum yükleme boyutu (KB) |
| `file_manager.accepted_mimes` | resim / pdf / word / excel / metin | İzin verilen MIME tipleri                 |
| `file_manager.allow_video`    | `false`                            | `video/*` yüklemeleri kabul etme toggle'ı |
| `file_manager.allow_audio`    | `false`                            | `audio/*` yüklemeleri kabul etme toggle'ı |

Backend validation her upload isteğinde bu ayarları okur — frontend mime filtresini atlatmak sunucu tarafında yine reddedilir.

### İmzalı Paylaşım Bağlantıları (`share`)

Kimlik doğrulaması gerektirmeksizin dosyaya süreli erişim sağlayan HMAC-SHA256 imzalı bağlantılar. `config('file-manager.share.enabled')` değerinin `true` olması gerekir.

| Anahtar             | Tip  | Varsayılan | Açıklama                                              |
| ------------------- | ---- | ---------- | ----------------------------------------------------- |
| `enabled`           | bool | `true`     | Paylaşım bağlantısı özelliğini etkinleştirir          |
| `default_ttl_hours` | int  | `24`       | Varsayılan bağlantı geçerlilik süresi (saat)          |
| `max_ttl_hours`     | int  | `720`      | İzin verilen maksimum geçerlilik süresi (30 gün)      |
| `allow_revoke`      | bool | `true`     | Süresi dolmadan bağlantı iptaline izin verir          |

İptal edilen token'lar `file_manager_share_revocations` tablosunda `(media_id, signed_token_hash)` composite unique index ile saklanır. Token doğrulaması oluşturulduğu `media_id` ile karşılaştırılır — farklı bir media kaydına karşı aynı token geçerli sayılmaz.

#### Çöp kutusu ve paylaşım bağlantısı erişimi

Bir dosyayı soft-delete etmek (Çöp Kutusu'na taşımak) dosyayı anında erişilemez kılar:

- `{media}` parametresini route üzerinden çözen tüm istekler — imzalı share show, kimlik doğrulamalı indirme, yeniden adlandırma, kopyalama, silme — çöpteki dosya için **404** döner. Yanıt, var olmayan bir dosyanın yanıtıyla aynıdır; böylece dosyanın Çöp Kutusu'nda bulunup bulunmadığı dışarıya sızmaz (oracle yok).
- Çöpteki bir dosya için yeni paylaşım bağlantısı oluşturmak da **404** döner.
- **Silme mevcut paylaşım bağlantılarını otomatik olarak iptal etmez.** Dosya için geçerli bir imzalı bağlantı varsa (süresi dolmamış, iptal edilmemiş), dosya çöpteyken 404 döner; dosya geri yüklenirse bağlantı yeniden çalışır. Geri yükleme sonrasında da erişimi kalıcı olarak engellemek için `POST /file-manager/share/revoke` çağrısı yapın — iptal işlemi geri yüklemeden sonra da geçerliliğini korur.
- Bir dosyayı geri yüklemek, süresi dolmamış ve iptal edilmemiş paylaşım bağlantıları üzerinden erişimi yeniden etkinleştirir.
- `php artisan file-manager:purge-trash` dosyayı diskten kalıcı olarak siler. Purge sonrasında iptal edilmemiş bağlantılar bile kalıcı olarak 404 döner.

**Route binding notu:** `{media}` route parametresi, servis provider'da kayıt edilen `Route::model('media', config('media-library.media_model'))` üzerinden çözülür. Bu, uygulamadaki `{media}` parametresi kullanan tüm route'ların — kendi özel route'larınız dahil — yapılandırılmış model sınıfının bir instance'ını alacağı anlamına gelir (SoftDeletes global scope uygulanmış olarak). Çöpteki kayıtlar binding tarafından varsayılan olarak hariç tutulur; kendi controller'larınızda çöpteki kayıtlara açıkça ihtiyaç duyduğunuzda `withTrashed()` kullanın.

### Çöp kutusu davranışı (`enable_trash`)

`config/file-manager.php` içindeki `enable_trash` anahtarı soft-delete vs hard-delete için tek yetkili kaynaktır:

```php
// config/file-manager.php
'settings' => [
    'enable_trash' => true,  // hard delete için false yapın
],
```

- **`true` (varsayılan)** — silme işlemi öğeyi Çöp Kutusu'na gönderir (soft-delete). Sidebar'da Çöp Kutusu girişi görünür. Geri Yükle ve Çöpü Boşalt kullanılabilir.
- **`false`** — tüm silme işlemleri öğeyi anında kalıcı olarak siler. Sidebar'da Çöp Kutusu girişi gizlenir.

Hem backend action'ları (`DeleteFileAction`, `DeleteFolderAction`) hem de Vue bileşeni bu değeri okur. Config, Inertia shared props (`fileManagerSettings.enable_trash`) aracılığıyla otomatik paylaşılır; component'in `:enable-trash` prop'una ihtiyacı yoktur — yalnızca config değerini instance bazında ezmek istediğinizde geçin.

> **Çöp kutusu yalnızca FileManager `files` koleksiyonunu kapsar.** Diğer tüm koleksiyonlardaki medya — avatar, logo, FormBuilder dosya ekleri, editör görselleri — `enable_trash` değerinden bağımsız olarak her zaman kalıcı silinir. Yayınlanan `App\Models\Media` stub'ı `delete()`'i override ederek bu silmeleri `forceDelete()`'e çevirir; bu, Spatie'nin kendi içinden yaptığı silmeleri de kapsar (örn. `clearMediaCollection()` ile single-file koleksiyon değişimi). Aksi hâlde bu kayıtlar görünmez soft-delete satırları olarak birikir: Çöp Kutusu UI'ında listelenmez, purge komutu onları atlar, depolama kotasını şişirmeye devam eder ve dosyaları diskte kalır. `file-manager:purge-trash` ayrıca eski kurulumlardan kalan `files` dışı trashed satırları yaş şartı olmadan süpürür.

## İzinler

Component izin kontrolü yapmaz — bu route'ların tek backend kapısı olan `FileManagerAuthorizer` her isteği kontrol eder. Çözümlenen context definition'ının `authorize` closure'una tam olarak dört ability'den biri gönderilir:

| Ability | İşlemler | Built-in `global` yetkisi |
| --- | --- | --- |
| `read` | tree, klasör içeriği, favoriler/çöp listeleri, indirme | `files.read` |
| `create` | upload, klasör oluşturma, dosya kopyalama | `files.create` |
| `update` | yeniden adlandırma, taşıma, favori değiştirme, geri yükleme, paylaşma/iptal context kontrolü | `files.update` |
| `delete` | dosya/klasör/toplu silme, çöpü boşaltma, kalıcı silme | `files.delete` |

Built-in kurallar:

- **Kullanıcı bağlamı** — kimliği doğrulanmış kullanıcı bağlam kullanıcısının KENDİSİ ise izinlidir; değilse policy `read` için `users.read`, tüm mutasyonlar için `users.update` kullanır
- **Global bağlam** — her ability'yi birebir eşleşen `files.*` yetkisine bağlar; bilinmeyen ability'ler fail-closed davranır
- **Auto-resolve context'ler** — Laravel policy'lerine delegate eder: `read` için `$user->can('view', $owner)`, her mutasyon için `$user->can('update', $owner)`
- **Özel kayıtlar** — `read`, `create`, `update` veya `delete` alır; kit deprecated `write` ability'sini hiçbir zaman göndermez

`files` kaynağı `create / read / update / delete` yetenekleriyle seed edilir; bu yetkileri Roller panelinden rollere atayın. Özel context'ler için policy yazın veya register sırasında permission tabanlı bir closure geçin.

Paylaşım bağlantısı işlemleri iki ayrı izin kullanır:

- `share-media` — imzalı paylaşım bağlantısı oluşturma (`POST /file-manager/share`)
- `revoke-share-media` — süresi dolmadan bağlantı iptali (`POST /file-manager/share/revoke`)

## İlgili Yapı

- Spatie Media Library
- sabit context bazlı disk yerleşimi için özel `MediaPathGenerator`
- istek başına yetkilendirme için `FileManagerAuthorizer`
- `src/Domain/FileManager/Support/` içindeki `ContextRegistry` + `ContextDefinition` (vendor-resident; namespace `Lvntr\StarterKit\Domain\FileManager\Support\`)

## Tam Sayfa Montajı

FileManager'ı dış scroll açmadan admin sayfasında tam dolduracak şekilde:

```vue
<AdminLayout :title="$t('sk-file.title')">
    <div class="flex min-h-0 flex-1">
        <FileManager context="global" height="100%" class="flex-1" />
    </div>
</AdminLayout>
```

`admin-content` CSS sınıfı flex-column düzeninde — page-header sabit yükseklikte. Wrapper `flex min-h-0 flex-1` ile kalan dikey alanı tüketir, `height="100%"` FileManager'a bu alanı doldurtur. İç scroll yalnızca grid içinde yaşanır.

## Composable Erişimi (İleri Düzey)

Component'i dışarıdan yönetmek gerekiyorsa:

```ts
import { useFileManager } from '@lvntr/components/FileManager/composables/useFileManager';

const fm = useFileManager({ context: 'user', contextId: userId });

await fm.loadTree();
await fm.loadContents(null); // root
fm.setSort('size', 'desc');
```

Dışa açılan state: `tree`, `contents`, `currentFolderId`, `breadcrumb`, `loading`, `sort`, `direction`, `selectedKeys`, `selectionCount`, `selectedItems`, `pendingUploads`.

Metodlar: `loadTree` / `loadContents` / `loadFavorites` / `loadTrash` / `refresh` / `setSort` / `toggleSortDirection` / `isSelected` / `toggleSelect` / `setSelection` / `clearSelection` / `selectAll` / `createFolder` / `renameFolder` / `renameFile` / `copyFile` / `toggleFavorite` / `restoreItem` / `permanentlyDeleteItem` / `emptyTrash` / `deleteFolder` / `deleteFile` / `bulkDelete` / `bulkForceDelete` / `moveItem` / `uploadFiles` / `dismissPending`.

`uploadFiles(files, folderId?)` artık `{ uploaded: FileItem[], errors: string[] }` döndürür. Her dosyanın progress'i `pendingUploads` ref'i üzerinden okunur — her entry `{ tempId, name, size, mimeType, progress, error, folderId }` şeklinde; başarılı olanlar otomatik temizlenir, hatalı olanlar `dismissPending(tempId)` çağrılana kadar kalır.

## Önerilen Kullanım

Dosya yöneticisini admin kontrollü yüklemeler ve düzenli medya akışları için kullanın. Basit tek dosya alanlarında ise FormBuilder file upload alanları ile birlikte değerlendirin.
