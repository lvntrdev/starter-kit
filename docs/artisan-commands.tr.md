# Artisan Komutları

Bu döküman starter kit için komut referansıdır. DDD ile ilgili mimari notlar ayrı olarak [ddd.tr.md](./ddd.tr.md) içinde tutulur.

## Son Kullanıcı Komutları

| Komut                                     | Amaç                                                             |
| ----------------------------------------- | ---------------------------------------------------------------- |
| `php artisan sk:doctor`                   | Ortam sağlık kontrollerini çalıştırır ve sorunları raporlar      |
| `php artisan sk:install`                  | Starter kit'i projeye kurar                                      |
| `php artisan sk:update`                   | Kurulu kit dosyalarını güvenli şekilde günceller                 |
| `php artisan sk:upgrade`                  | Eski starter-kit/Laravel ana sürümünü güncel hatta yükseltir     |
| `php artisan sk:publish`                  | İsteğe bağlı bileşenleri, dil dosyalarını veya config'i yayınlar |
| `php artisan sk:eject`                    | Vendor'da çalışan bir domain'i tam özelleştirme için uygulamaya çıkarır |
| `php artisan make:sk-domain`              | Yeni bir domain iskeleti üretir                                  |
| `php artisan remove:sk-domain`            | Üretilmiş bir domain'i kaldırır                                  |
| `php artisan env:sync`                    | `.env` anahtarlarını `.env.example` içine senkronize eder        |
| `php artisan env:sync --reverse`          | `.env` içinde eksik kalan anahtarları kontrol eder               |
| `php artisan site:install`                | Lokal/dev kullanım için site verisini sıfırlayıp yeniden kurar   |
| `php artisan sk:seed-permissions --fresh` | Rol ve yetki verilerini config'ten yeniden üretir                |
| `php artisan postman:sync`                | Scramble OpenAPI spec'ini Postman'a gönderir                     |
| `php artisan apidog:sync`                 | Scramble OpenAPI spec'ini Apidog'a gönderir                      |
| `php artisan sk:redact-activity-secrets`  | Mevcut aktivite kayıtlarından kimlik bilgilerini geri döndürülemez biçimde kaldırır |
| `php artisan file-manager:purge-trash`    | Eski Dosya Yöneticisi çöpünü kalıcı olarak siler                 |
| `php artisan encryption:key`              | Adanmış bir `DATA_ENCRYPTION_KEY` üretir, eski anahtarı korur    |
| `php artisan encryption:rekey`            | Ayarları ve 2FA secret'larını birincil şifreleme anahtarına taşır |
| `php artisan encryption:health`           | Her şifreli değerin hangi anahtara ihtiyaç duyduğunu raporlar (salt-okunur) |

## `sk:doctor`

Bir dizi ortam sağlık kontrolü çalıştırır ve her birinin sonucunu raporlar.

```bash
php artisan sk:doctor
php artisan sk:doctor --json
php artisan sk:doctor --only=database-connection,redis-connection
php artisan sk:doctor --only=timezone-storage
```

- `--json` tablo yerine makine okunabilir JSON çıktı üretir
- `--only=<seçiciler>` virgülle ayrılmış seçili kontrolleri çalıştırır. Her seçici, kontrolün adının küçük harfe çevrilip boşlukların tire ile değiştirilmiş halidir (örn. "Database Connection" → `database-connection`) — herhangi bir seçiciyi bu kuralla türetebilirsiniz

Kontroller (ad → `--only` seçicisi):

| Kontrol                | `--only` seçicisi        |
| ---------------------- | ------------------------ |
| PHP Extensions         | `php-extensions`         |
| Node Version           | `node-version`           |
| Database Connection    | `database-connection`    |
| Redis Connection       | `redis-connection`       |
| Passport Keys          | `passport-keys`          |
| Storage Symlink        | `storage-symlink`        |
| Writable Directories   | `writable-directories`   |
| Log Channel            | `log-channel`            |
| Log Stack              | `log-stack`              |
| Queue Driver           | `queue-driver`           |
| Queue Worker           | `queue-worker`           |
| Schedule Configured    | `schedule-configured`    |
| Mail Driver            | `mail-driver`            |
| NPM Build Artifacts    | `npm-build-artifacts`    |
| Config Cache           | `config-cache`           |
| FileManager Disk       | `filemanager-disk`       |
| Theme Manifest         | `theme-manifest`         |
| Timezone Storage       | `timezone-storage`       |
| Activity Log Secrets   | `activity-log-secrets`   |
| Permission Matrix      | `permission-matrix`      |
| Unresolved Routes      | `unresolved-routes`      |
| Data Encryption Key    | `data-encryption-key`    |

`ActivityLogSecretsCheck`, bir `activity_log` satırı hâlâ parola hash'i, token veya secret içeriyorsa FAIL döndürür. Bu durum, paket güncellenip (yeni satırlardaki sızıntı anında kapanır) `php artisan migrate` çalıştırılmadığında, yani geçmiş satırlar hiç temizlenmediğinde ortaya çıkar. Veritabanını yedekleyip `php artisan migrate` ya da `php artisan sk:redact-activity-secrets` çalıştırın; kaldırma geri döndürülemez. Aktivite kaydı tablosunun bulunmaması veya JSON payload kolonu olmaması OK, decode edilemeyen JSON payload WARN, veritabanı hatası ise başarı değil WARN sonucu verir.

Kontrol, tam temizlik geçişi değil **sınırlı ve salt-okunur bir sondadır**. Birincil anahtara göre sıralı ilk 500 satırı okur — MySQL, MariaDB, SQLite ve PostgreSQL'de aynı sabit maliyet — ve kararı SQL'de değil PHP'de verir; böylece `Password` gibi farklı yazılmış bir anahtar, JSON kolonunun collation'ından bağımsız olarak yakalanır. Mesajlar tam olarak ölçüleni söyler: büyük bir tabloda bulgu bir alt sınır olarak ("en az N") raporlanır, temiz sonuç ise tüm tabloyu aklamak yerine taradığı pencereyi adlandırır. Tam sayım için `php artisan sk:redact-activity-secrets --dry-run --all` çalıştırın — `--all` zorunludur, çünkü onsuz komut MySQL, MariaDB ve SQLite'ta SQL tarafında bir anahtar-adı ön filtresi kullanır ve farklı yazılmış bir anahtar bu filtreden kaçabilir.

`PermissionResourcesDriftCheck`, `config/permission-resources.php` paketin gönderdiği her kaynak ve yeteneği kapsamıyorsa WARN döndürür — örneğin FileManager'ın `files.create` / `files.update` / `files.delete` ayrımından önce kurulmuş ve hâlâ eski kümeyi tanımlayan bir uygulama. Bu dosya updater'ın "asla dokunma" listesindedir; bu bilinçli bir tercihtir, çünkü KENDİ kaynaklarınızı orada tanımlarsınız. Bedeli, paketin yeni girdilerinin kendiliğinden gelmemesidir ve belirtisi, daha önce çalışan bir ekranda 403 almaktır. Kontrol tek yönlüdür: kendi eklediğiniz kaynaklar asla raporlanmaz. Çözüm, listelenen girdileri `config/permission-resources.php` dosyasına eklemek ve `php artisan sk:seed-permissions` çalıştırmaktır. Eksik veya boş config WARN, okunamayan paket kopyası da başarı yerine WARN döndürür.

`TimezoneStorageCheck`, `config('app.timezone')` tam olarak `UTC` değilse FAIL döndürür. Bu ayar doğruysa varsayılan bağlantıdan ayrıca `SELECT @@session.time_zone` değerini okur. MySQL/MariaDB bağlantısında yalnız `+00:00` ve `UTC` başarılıdır; `SYSTEM` ve diğer tüm değerler FAIL döndürür, çünkü uygulama satırları tutarlı okurken bile `TIMESTAMP` değerleri diskte offset'li olabilir. Sorgu hatası veya eksik sonuç hiçbir zaman başarı sayılmaz, WARN döndürür. Diğer veritabanı sürücüleri oturum kontrolünü uygulanamaz olarak belirten OK sonucu verir. Gösterim yapılandırmasını `APP_DISPLAY_TIMEZONE` ile ayrı tutun; bağlantı sözleşmesi ve mevcut veri dönüşüm rehberi için [Saat Dilimleri](timezone.tr.md) belgesine bakın.

`UnresolvedRouteCheck`, kitin `check.permission` middleware'ini taşıdığı hâlde hiçbir izin türetilemeyen her route için FAIL döndürür — ability map'in tanıdığı bir `<resource>.<action>` adı yok, açık bir `check.permission:<perm>` argümanı yok, muafiyet listesinde de kaydı yok. Böyle bir route controller'ına **yetkilendirilmeden** ulaşır. Varsayılan olarak middleware onu geçiriyor ve kısıtlanmış bir uyarı logluyor; **hiçbir sürüm bunu mevcut bir kurulum için değiştirmiyor**. `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` (config `starter-kit.permissions.allow_unresolved`) vermek listelenen her route'u 403'e çevirir — opt-in budur ve yeni kurulan bir proje bu satırla zaten geliyor. Kontrol bu ayardan bağımsız olarak FAIL raporlar; bu bilinçlidir — görevi, mevcut yapılandırmanın neye izin verdiğini değil, dönüşün neyi reddedeceğini göstermektir. Paketin gönderdiği route'lar `CheckResourcePermission` içindeki route-adı haritası sayesinde kendiliğinden çözülür; yani bu kontrolün listelediği şey sizin kendi route'larınız ve adını değiştirdiğiniz kit route'larıdır. Her birini ya haritada karşılığı olan bir `<resource>.<action>` adına çevirerek, ya açık bir izin argümanıyla kapıya alarak, ya da bilinçli olarak izinsizse `starter-kit.permissions.unrestricted_routes` altında tanımlayarak düzeltin. Sıralı yol için [UPGRADE.tr.md](UPGRADE.tr.md) belgesine bakın.

`DataEncryptionKeyCheck` yalnızca config okur — ne tablo taraması ne şifre çözme — ve asla FAIL döndürmez. Adanmış anahtar yapılandırılmamışsa (`DATA_ENCRYPTION_KEY` boş) WARN döner: hassas ayarlar ve 2FA secret'ları hâlâ `APP_KEY` ile şifrelidir ve bir sunucu taşımasında çalıştırılacak `php artisan key:generate` bunları okunamaz hale getirir. Adanmış anahtar varken `DATA_ENCRYPTION_PREVIOUS_KEYS` boş değilse WARN döner: rotasyon yarım kalmıştır. Adanmış anahtar varken önceki-anahtar listesi boşsa OK döner. Bkz. [Veri Şifreleme](encryption.tr.md) ve aşağıdaki `encryption:key` / `encryption:rekey` / `encryption:health`.

Çıkış kodları:

| Kod | Anlam                                   |
| --- | --------------------------------------- |
| `0` | Tüm kontroller başarılı                 |
| `1` | En az bir kontrol WARN döndürdü         |
| `2` | En az bir kontrol FAIL döndürdü         |

## `sk:install`

İlk kurulumda kullanılır.

```bash
php artisan sk:install
php artisan sk:install --force
php artisan sk:install --adopt
php artisan sk:install --adopt --dry-run
php artisan sk:install --no-interaction
php artisan sk:install --without-ai-skill
php artisan sk:install --without-eject
php artisan sk:install --resume
```

- `--force` mevcut yayınlanabilir dosyaların üzerine yazar **ve** aşağıda anlatılan "zaten kurulu" güvenlik durdurmasını atlar. Her koruma kuralının opt-out'udur: tüketicinin düzenlediği bir yayınlanmış dosya da, hash kaydında hiç izi olmayan bir dosya da üzerine yazılır
- `--adopt` zaten kurulu olduğu hâlde kaydını kaybetmiş (stateless deploy, temizlenmiş `storage/`) bir uygulama için `storage/starter-kit/hashes.json` dosyasını gönderilen stub'lardan yeniden kurar. Hiçbir dosya kopyalamaz, hiçbir migration çalıştırmaz ve `.env` dosyasına asla dokunmaz; yazacağı kaydı önizlemek için `--dry-run` ile birlikte kullanın
- `--dry-run` neyin yazılacağını yazdırır ve hiçbir şey yazmadan çıkar
- `--no-interaction` tüm varsayılanları otomatik kabul eder; CI veya script tabanlı kurulumlar için uygundur
- `--without-ai-skill` Lvntr Starter Kit AI skill'lerinin yayınlanmasını tamamen atlar — hem Claude Code kopyaları (`.claude/skills/`) hem de Codex aynası (`.codex/skills/`). Kit'in skill bundle'ını ne Claude Code ne Codex ile kullanan consumer'lar için
- `--without-eject` ilk kurulumda varsayılan `User` ve `Role` domain eject'ini atlar; runtime vendor'da kalır ve `class_alias` ile çözülür. Bu flag'i atlarsanız `app/Domain/User/` ve `app/Domain/Role/` otomatik oluşturulur. Sahiplik takası için [install.tr.md](./install.tr.md) belgesine bakın.
- `--resume` daha önce yarıda kalmış bir kurulumu, zaten tamamlandığı işaretlenmiş adımları atlayarak devam ettirir. Tam resume akışı için [install.tr.md](./install.tr.md) belgesine bakın.

**Bu bir ilk kurulum komutudur, onarım aracı değil.** Banner yazdırılmadan önce fail-closed bir tespit taraması, kit şema tablolarını ve yalnızca kuruluma özgü yolları arar; eşleşen bir hash kaydı olmadan bunlardan herhangi birini bulursa komut **tek bayt yazmadan durur** ve `sk:update`, `sk:publish --tag=<alan>` ya da `--adopt` seçeneklerine yönlendirir. Mevcut bir `.env` ne ilk kurulumda ne de yeniden çalıştırmada asla üzerine yazılmaz: `.env.example` içindeki eksik anahtarlar eklenir, yalnızca ilk kuruluma özgü anahtarlar sadece yoksa yazılır ve mevcut hiçbir değer değiştirilmez.

**Çıkış kodları.** **Zorunlu** bir adımın (publish, migration, seeder, izin seeding, Passport anahtarları, encryption anahtarları) başarısız olması çalıştırmayı durdurur, checkpoint'i `--resume` için bekler hâlde bırakır, hash kaydını yazmaz ve sıfırdan farklı bir kodla çıkar. Frontend adımları (`npm install`, Wayfinder üretimi, `npm run build`, `composer dump-autoload`, cache temizlikleri) bilinçli olarak ölümcül değildir — uyarır, elle çalıştırılacak komutu yazdırır ve kapanış özetinde tekrar listelenir.

**Migration adımı, veritabanında zaten tablo varsa nasıl ilerleneceğini sorar.** Varsayılan (ve etkileşimsiz bir oturumun alabileceği tek seçenek) `Yalnızca bekleyen migration'ları çalıştır`tır; `Migration'ları atla` her zaman sunulur. Yıkıcı `Tüm tabloları düşür ve migration'ları sıfırdan çalıştır` seçeneği şu durumlarda **tamamen sunulmaz**: `APP_ENV` production'a benziyorsa, `APP_DEBUG` kapalıysa, oturum prompt açamıyorsa veya mevcut herhangi bir tablo zaten satır tutuyorsa. Sunulduğunda da seçilmesi, bir text prompt'ta veritabanı adının (veya `fresh` kelimesinin) yazılmasını gerektirir. Boş cevap dâhil başka her şey, hiçbir şey düşürmeden ek yapıcı `migrate` yoluna döner.

Config aşaması, `config/database.php` içindeki mevcut `mysql` ve `mariadb` dizilerine idempotent biçimde `'timezone' => '+00:00'` ekler. Mevcut bir değeri korur, eksik bağlantıyı atlar ve diğer sürücülere dokunmaz. UTC dışı bir oturumda zaten veri taşıyan bir veritabanına karşı yeniden çalıştırıldığında adım atlanır ve `sk:upgrade` komutuna yönlendirir. Bkz. [install.tr.md](./install.tr.md) ve [Saat Dilimleri](timezone.tr.md).

## `sk:update`

`composer update` sonrasında kullanılır.

```bash
php artisan sk:update
php artisan sk:update --dry-run
php artisan sk:update --force
php artisan sk:update --without-ai-skill
```

- `--without-ai-skill` bu çalışmada `.codex/skills/` AI-skill aynasının yeniden üretilmesini atlar. (Kurulum sırasındaki `--without-ai-skill` tercihi otomatik korunur — atlanan skill'ler asla yeniden eklenmez.)

**Düzenlemeleriniz korunur — paket sahipli dosyalarda da.** Kopyalanan her dosya, kurulum/güncelleme anında kaydedilen hash ile karşılaştırılır; içeriği artık bu kayıtla eşleşmeyen dosya korunur ve "Skipped" başlığı altında listelenir. Bu artık `app/Enums/PermissionEnum.php` dosyasını da kapsıyor: dosya paket sahiplidir ve her güncellemede yenilenir, ancak aynı zamanda public `for()` / `allFor()` yardımcılarına sahip backed bir enum'dur — dolayısıyla eklediğiniz bir proje yeteneği (`case Approve = 'approve';`) sessizce ezilmek yerine korunur. Korunan kopya ayrı raporlanır, çünkü paket kendi case'lerinin var olmasını bekler — dosyanızı `vendor/lvntr/laravel-starter-kit/stubs/` altındaki aynı göreli yol ile karşılaştırıp yeni case'leri birleştirin ya da `--force` ile paket sürümünü alıp düzenlemelerinizi geri dönülmez şekilde bırakın. Hash kaydı olmayan bir kopya (hash takibinden önceye dayanan kurulum) dokunulmamış varsayılmaz; diğer tüm untracked dosyalarla aynı etkileşimli seçim ekranında sorulur.

## `sk:upgrade`

Laravel 12 -> 13 gibi starter-kit veya Laravel major geçişlerinde kullanılır.

Komut ayrıca mevcut kurulumlar için idempotent AST config adımları çalıştırır: `config/app.php` içindeki eski `'display_timezone' => env('APP_TIMEZONE', ...)` girdisi `APP_DISPLAY_TIMEZONE` okuyacak şekilde yeniden yazılır; `config/database.php` içindeki mevcut MySQL/MariaDB dizilerine, consumer değerlerinin üzerine yazılmadan eksik UTC `timezone` girdileri eklenir.

Varsayılan MySQL/MariaDB oturumu UTC değilse ve `users` tablosunda veri varsa komut uyarı verir ve bağlantıyı sabitlemeden önce açık onay ister. Reddetme, inceleme hatası veya `--force` bulunmayan etkileşimsiz çalışma (`--no-interaction` ya da TTY olmayan shell) düzenlemeyi atlar ve daha sonra nasıl uygulanacağını bildirir. `--force` bu onay kapısını bypass eder. Komut saklanan satırları hiçbir zaman dönüştürmez; önce [tek seferlik dönüşüm rehberini](timezone.tr.md#mevcut-veriler-için-tek-seferlik-dönüşüm) izleyin. Upgrade tekrar çalıştırıldığında config girdileri çoğaltılmaz.

```bash
php artisan sk:upgrade
php artisan sk:upgrade --force
php artisan sk:upgrade --skip-build
```

## `sk:publish`

Bunu yalnızca paket varlıklarının proje sahipli kopyalarına ihtiyacınız varsa kullanın.

```bash
php artisan sk:publish
php artisan sk:publish --tag=components
php artisan sk:publish --tag=datatable
php artisan sk:publish --tag=form
php artisan sk:publish --tag=tabs
php artisan sk:publish --tag=skeleton
php artisan sk:publish --tag=ui
php artisan sk:publish --tag=filemanager
php artisan sk:publish --tag=composables
php artisan sk:publish --tag=plugins
php artisan sk:publish --tag=lang
php artisan sk:publish --tag=config
php artisan sk:publish --tag=helpers
```

## `sk:eject`

Runtime'ı vendor paketinden çalışan bir domain'i tamamen özelleştirmek istediğinizde kullanılır. Eject, domain'in backend sınıflarını `app/Domain/{Name}/` altına kopyalar, namespace'lerini `App\Domain\{Name}\` olarak yeniden yazar, domain'e ait Vue sayfalarını tazeler ve event/listener binding'lerini `app/Providers/DomainServiceProvider.php` dosyasına ekleyerek audit log'un kesintisiz çalışmasını sağlar. Önce `--dry-run` ile neyin değişeceğini önizleyin.

`--force`, `--dry-run` veya `--no-interaction` verilmediği sürece komut, herhangi bir işlem yapmadan önce onay ister — eject tek yönlü bir takastır (domain, `composer update` ile kit runtime güncellemesi almayı bırakır). `sk:install`'in kendi dahili varsayılan-domain eject'i her zaman `--force` geçer; bu yüzden taze kurulum akışı bu onay istemiyle kesintiye uğramaz.

```bash
php artisan sk:eject User
php artisan sk:eject User --dry-run
php artisan sk:eject User --force
php artisan sk:eject User --no-vue
php artisan sk:eject Role --destination=/tmp/eject-preview
php artisan sk:eject ApiClient          # controller + request + resource (ApiClient + ApiToken)
php artisan sk:eject ContentLanguage    # domain + controller + request + resource
```

- `--dry-run` dosya yazmadan kopyalama/yeniden yazma/enjeksiyon planını ekranda gösterir. Her zaman önce bunu çalıştırın.
- `--force` zaten var olan dosyaların üzerine yazar — hem backend `app/Domain/{Name}/` ağacı hem de domain'in Vue sayfaları. **`--force` olmadan eject hiçbir mevcut dosyayı ezmez:** zaten var olan bir `app/Domain/{Name}/` komutu erken sonlandırır ve zaten var olan her Vue sayfası olduğu gibi bırakılıp korunan olarak raporlanır — yalnızca eksik sayfalar yazılır. Bu, `sk:install` ile gelen sayfalarda yaptığınız düzenlemeleri korur.
- `--no-vue` domain'e ait Vue sayfalarını tazelemez; yalnızca backend sınıfları eject edilir.
- `--destination=<yol>` çıktıyı uygulama köküne yazmak yerine belirtilen dizine yönlendirir. İzole test amacıyla kullanılır.
- `--skip-autoload` eject sonundaki `composer dump-autoload` çağrısını atlar. Yalnızca çağıran süreç (örneğin `sk:install`) dump'ı kendisi yapacaksa kullanın. Bu flag olmadan eject her zaman autoload'u yeniler; yenileme başarısız olursa sıfırdan farklı kod döner.

> **Çıkış kodu:** Composer'ın autoload yenilemesi başarısız olursa (örn. `composer` yok ya da hata verir), komut hatayı yazar ve dosyalar kopyalanmış olsa bile **sıfırdan farklı kod ile çıkar** — böylece CI ve scriptler bozuk autoload'ı başarılı eject sanmaz. Elle `composer dump-autoload` çalıştırıp tekrar doğrulayın.

### Eject edilebilir domain'ler

On dört domain eject edilebilir. Bu listede yer almayan domain'ler zaten uygulama sahipli olduğundan eject gerektirmez.

| Domain            | Backend sınıflar      | Vue sayfaları | Eject edilen HTTP katmanı   | Enjekte edilen event binding'ler |
| ----------------- | --------------------- | ------------- | --------------------------- | -------------------------------- |
| `User`            | evet                  | evet          | —                           | 3 (Created/Updated/Deleted)      |
| `Role`            | evet                  | evet          | —                           | 3 (Created/Updated/Deleted)      |
| `Setting`         | evet                  | evet          | controller + request'ler    | —                                |
| `Logs`            | evet                  | evet          | controller + request'ler    | 1 (FilesDeleted)                 |
| `ActivityLog`     | evet                  | evet          | controller                  | —                                |
| `ApiClient`       | evet                  | —             | ApiClient + ApiToken controller'ları + request'ler + resource'lar | — |
| `ApiRoute`        | evet                  | evet          | controller                  | —                                |
| `ContentLanguage` | evet                  | —             | controller + request'ler + resource | —                        |
| `SystemHealth`    | hayır (yalnızca controller) | —       | controller                  | —                                |
| `Definitions`     | hayır (yalnızca controller) | —       | API + Service controller'ları | —                              |
| `MediaUpload`     | hayır (yalnızca controller) | —       | controller                  | —                                |
| `Files`           | hayır (yalnızca Vue)  | evet          | —                           | —                                |
| `Session`         | evet                  | —             | —                           | —                                |
| `Media`           | evet                  | —             | —                           | —                                |

**`ApiClient` API-token akışını da eject eder:** ApiClient domain'i hem OAuth client'ı hem de kişisel erişim token'ı action'larına sahiptir; bu yüzden `sk:eject ApiClient` hem `ApiClientController`'ı hem `ApiTokenController`'ı (FormRequest'leri ve API Resource'larıyla birlikte) kopyalar ve `api-client-route.php` ile `api-token-route.php` import'larını yeniden yazar. Tek seferlik Passport client-secret gösterimi birebir korunur — eject dosyayı taşır, davranışı değiştirmez.

**`SystemHealth`, `Definitions` ve `MediaUpload` yalnızca controller'dır:** bunların `app/Domain/{Name}` backend ağacı yoktur. `SystemHealth` Artisan + `Gate`'i doğrudan controller'ından sürer; `Definitions` hem `Api\DefinitionController` hem `Service\DefinitionServiceController`'ı eject eder (ikisi de vendor `DefinitionService`'i sarar — servis vendor'da kalır); `MediaUpload` `media.destroy` route'u paylaşılan `routes/web.php`'de olan `Api\MediaUploadController`'ı eject eder. Hiçbiri FormRequest ya da `app/Domain` klasörü taşımaz; dolayısıyla bir controller kopyalanmadıkça autoload'u etkileyen sınıf eklenmez.

**Model'ler uygulama sahipli kalır — eject hiçbir Model'i taşımaz.** `App\Models\{ContentLanguage,Media,Definition,...}` uygulamanızda publish kalır ve asla vendor'a alias'lanmaz (bir `App\Models\X` alias'ı Laravel'in `XPolicy` keşfini ve route-model binding'ini bozardı). Vendor controller/domain'ler bu modellere `App\` FQCN ile başvurur; eject edilen bir `app/Domain/ContentLanguage` da o `App\Models\ContentLanguage` referansını değiştirmeden korur.

**Auth ve Helper'lar neden eject edilemiyor:** Auth ekranları zaten %100 uygulama sahipli — `sk:update` onları güncel tutar, eject gerekmez. `sk-helpers.php` global helper'ları tek bir override edilebilir dosya olarak gelir; ihtiyaç duyulmayan kısımlar silinir.

**`Files` yalnızca Vue'dur:** `Files` eject ettikten sonra FileManager backend'i (controller, FormRequests, route-registry altyapısı) vendor'da yönetilmeye devam eder. Yalnızca admin Vue sayfaları (`resources/js/pages/Admin/Files/`) uygulamanıza kopyalanır; bu sayede kullanıcı arayüzü özelleştirilebilirken backend kit güncellemelerini almayı sürdürür. Geri almak için kopyalanan `resources/js/pages/Admin/Files/` dizinini silin — `app.ts` fallback mekanizması vendor sayfaları devreye sokar.

### Namespace yeniden yazımının kapsamı

Yalnızca eject edilen domain'in kendi namespace'i yeniden yazılır. Diğer tüm vendor referansları olduğu gibi kalır:

- `Lvntr\StarterKit\Domain\User\Actions\CreateUserAction` → `App\Domain\User\Actions\CreateUserAction`
- `use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;` — **değişmez** (`Shared` base sınıfları vendor'da kalır)
- `Lvntr\StarterKit\Http\Responses\ApiResponse` — **değişmez**
- Eject edilmeyen diğer domain'ler — **değişmez**

### Güncelleme-kaybı takası

> **Uyarı:** Bir domain'i eject ettikten sonra, o domain'in vendor runtime'ını etkileyen güvenlik veya hata düzeltmelerini içeren `composer update` sürümleri kendi kopyanıza uygulanmaz. Dosyalar size ait olur — upstream değişikliklerini kendiniz uygulamanız gerekir.

`sk:update`, `app/Domain/` altındaki backend dosyalara hiç dokunmaz (bunlar hash-tracked stub değildir). `--force` ile eject edilen Vue sayfaları normal hash-tracking kurallarına tabidir: düzenlediğinizde `sk:update` onları "özelleştirilmiş" olarak işaretler ve güncellemeyi atlar.

### Eject'i geri alma (v1: manuel)

`--revert` bayrağı gelecekteki bir sürüm için planlanmaktadır. Manuel geri alma adımları:

1. `app/Domain/{Name}/` klasörünü silin.
2. `app/Providers/DomainServiceProvider.php` içinden o domain'e ait `Event::listen(...)` satırlarını kaldırın.
3. `composer dump-autoload` çalıştırın.

`StarterKitServiceProvider` içindeki `class_alias` tanımları, `App\Domain\{Name}\*` importlarını otomatik olarak tekrar vendor kopyasına yönlendirir.

## `make:sk-domain`

Starter kit yapısına uygun yeni bir domain oluşturur.

```bash
# Sadece domain (geriye dönük uyumlu)
php artisan make:sk-domain Article

# Namespace'li
php artisan make:sk-domain Store/Product

# Temel seçenekler
php artisan make:sk-domain Product --admin --api --events --fields="name:string,price:decimal"
php artisan make:sk-domain Product --from-migration=2026_03_21_create_products_table.php

# Opt-in ekstralar — tekil flag'ler
php artisan make:sk-domain Article --with-policy --with-factory

# Opt-in ekstralar — toplu syntax
php artisan make:sk-domain Article --with=policy,factory,test

# İlişki scaffold'ı
php artisan make:sk-domain Article --with-relations --relations="belongsTo:User,hasMany:Comment"

# Tam
php artisan make:sk-domain Article --with=policy,factory,seeder,test,relations --relations="belongsTo:User,morphTo:commentable"
```

Temel flag'ler:

| Flag | Ne yapar |
| ---- | -------- |
| `--fields="name:string,age:integer"` | Virgülle ayrılmış `alan:tip` çiftleri. Mevcut tipler: `string`, `integer`, `bigInteger`, `unsignedBigInteger`, `float`, `decimal`, `boolean`, `text`, `longText`, `json`, `date`, `dateTime`, `timestamp`. Atlanırsa alan alan interaktif sorulur. |
| `--id-type=id\|uuid\|ulid` | Primary key stratejisi. `id` (varsayılan) auto-increment bigint'tir; `uuid`/`ulid` model'e ilgili `HasUuids`/`HasUlids` concern'ini ekler ve migration'daki `id` kolonunu değiştirir. Atlanırsa interaktif sorulur — `--from-migration` kullanıldığında tamamen atlanır (migration dosyasından tespit edilir). |
| `--api` / `--no-api` | API controller + route'ları zorla üretir veya zorla atlar. İkisi de verilmezse (varsayılan: evet) sorulur. |
| `--admin` / `--no-admin` | Admin controller + route'ları zorla üretir veya zorla atlar. İkisi de verilmezse (varsayılan: evet) sorulur. |
| `--events` / `--no-events` | Created/Updated/Deleted event'lerini ve loglayan listener'larını zorla üretir veya zorla atlar. İkisi de verilmezse (varsayılan: evet) sorulur. |
| `--soft-deletes` / `--no-soft-deletes` | Model ve migration'da `SoftDeletes`'i zorla etkinleştirir veya zorla devre dışı bırakır. İkisi de verilmezse (varsayılan: evet) sorulur — `--from-migration` kullanıldığında tamamen atlanır (migration dosyasından tespit edilir). |
| `--vue=none\|empty\|full` | Vue sayfa üretim modu; yalnızca Admin katmanı üretiliyorsa geçerlidir (aksi halde `none`'a zorlanır). `full` Index (DataTable) + Create/Edit (FormBuilder) üretir; `empty` yalnızca boş bir Index sayfası üretir; `none` Vue üretimini atlar. Atlanırsa interaktif sorulur (varsayılan: `full`). |
| `--vue-fields` / `--no-vue-fields` | Yalnızca `--vue=full` ile anlamlıdır. Üretilen DataTable kolonlarına ve FormBuilder'a model alanlarını dahil eder ya da yalnızca id içeren bir iskelet üretir. İkisi de verilmezse ve alan varsa (varsayılan: evet) sorulur. |
| `--from-migration=<dosya adı>` | Alanları, ID tipini ve soft-delete'i `--fields`/`--id-type`/promptlar yerine var olan bir migration dosyasından ayrıştırır, örn. `--from-migration=2026_03_21_create_products_table.php`. Tam ya da kısmi dosya adı kabul edilir (`database/migrations/` altında glob ile eşleştirilir). |

Opt-in flag'ler (v2):

| Flag | Ne üretir |
| ---- | --------- |
| `--with-policy` | Policy sınıfı |
| `--with-factory` | Factory |
| `--with-seeder` | Seeder |
| `--with-test` | Feature test |
| `--with-permissions` | Kaynağı (tüm ability'lerle) `config/permission-resources.php`'ye kaydeder, EN görünen ad ekler — TR etiketi ve rol ataması elle tamamlanmalı |
| `--with-relations` | İlişki scaffold'ı (`--relations` ile birlikte kullanılır) |
| `--with=<policy,factory,seeder,test,permissions,relations>` | Toplu syntax — yukarıdaki opt-in'lerin herhangi bir kombinasyonu tek flag'de; tekil `--with-*` flag'leri buna eklemeli olarak uygulanır |
| `--relations="belongsTo:User,hasMany:Comment,morphTo:commentable"` | Scaffold için ilişki tanımları. Desteklenen tipler: `belongsTo`, `hasMany`, `morphTo`. `--relations=` verilmesi `--with-relations`'ı zımnen içerir |

Action, DTO, Query, Request, Route ve Vue ekranı gibi paket konvansiyonlarını hızlıca kurmak istediğinizde kullanın.

## `remove:sk-domain`

Üretilmiş bir domain'i ve ilişkili dosyalarını kaldırır.

```bash
php artisan remove:sk-domain Product
php artisan remove:sk-domain Product --force
```

- `--force` onay istemini atlar

## `env:sync`

`.env.example` dosyasını projenin `.env` anahtarlarıyla uyumlu tutar.

```bash
php artisan env:sync
php artisan env:sync --reverse
```

`--reverse` güvenli bir kontrol modudur; dosya yazmaz, yalnızca `.env.example` içinde olup `.env` içinde eksik kalan anahtarları raporlar.

## `site:install`

Lokal geliştirmede temiz kurulum akışını tekrar çalıştırmak istediğinizde faydalıdır.

```bash
php artisan site:install
```

Komut onaydan önce hedef environment ve veritabanını gösterir, yalnızca `local` ve `setup` ortamlarında çalışır, production'a benzeyen environment adlarında ise kalıcı olarak engellenir.

v13.4.1 itibarıyla akış `passport:keys` ile varsayılan admin seed'i arasında `passport:client --personal --provider=users` adımını da koşturur; böylece sıfır kurulum sonrası kişisel access token üretebilecek çalışan bir yolunuz hazır olur, ek bir manuel adım gerekmez.

## `postman:sync`

Scramble tarafından üretilen OpenAPI spec'ini Postman'a iterek workspace koleksiyonunuzun güncel API yüzeyiyle senkron kalmasını sağlar.

```bash
php artisan postman:sync
```

`postman` ayar grubunu okur: `postman.api_key` ve `postman.workspace_id` zorunludur, başarılı gönderim sonrasında `postman.collection_id` Postman'dan dönen id ile güncellenir. Anahtar veya workspace id eksikse komut anlaşılır bir hata ile hemen durur — değerleri admin panelinde **Settings → API Clients → Postman** altından (ya da doğrudan ilgili satırları ekleyerek) doldurup komutu tekrar koşturun. Komut perde arkasında `App\Domain\ApiRoute\Actions\SyncPostmanAction` sınıfına delege edilir; ortak `OpenApiExporter` helper'ı `scramble:export` komutunu `storage/app/postman/` altında her çağrıda benzersiz bir geçici dosyaya yazar ve spec'i **değiştirmeden** Postman'e gönderir. Action önce taze koleksiyonu import eder, yeni UID'yi ayarlara yazar, sonra eski koleksiyonu best-effort siler — başarısız bir push mevcut çalışan koleksiyonu kaybetmez.

## `apidog:sync`

Aynı Scramble OpenAPI spec'ini, koleksiyonu Apidog üzerinde yansılayan ekipler için Apidog'a gönderir.

```bash
php artisan apidog:sync
```

`apidog` ayar grubunu okur: `apidog.access_token` ve `apidog.project_id` zorunludur. Değerlerden biri eksikse komut "not configured" hatası verip durur — değerleri **Settings → API Clients → Apidog** altından (ya da doğrudan ilgili satırları ekleyerek) doldurup komutu tekrar koşturun. Asıl iş `App\Domain\ApiRoute\Actions\SyncApidogAction` içinde yapılır ve `postman:sync` ile aynı `OpenApiExporter` helper'ını paylaşır — spec Apidog'a **değiştirilmeden** gönderilir, bu sayede push edilen proje gerçek sunucu kontratını aynen yansıtır.

## `sk:redact-activity-secrets`

Mevcut aktivite kaydı satırlarındaki hem `attribute_changes` hem `properties` JSON kolonlarından hassas anahtarları recursive olarak kaldırır; diğer tüm anahtarları korur. İşlem geri döndürülemez: çalıştırmadan önce veritabanı yedeği alın.

```bash
php artisan sk:redact-activity-secrets --dry-run
php artisan sk:redact-activity-secrets
php artisan sk:redact-activity-secrets --chunk=500
php artisan sk:redact-activity-secrets --all
```

| Flag | Amaç |
| --- | --- |
| `--dry-run` | Değişiklik yazmadan redact edilecek satırları raporlar |
| `--chunk=<satır>` | Her turda bu kadar satır işler (varsayılan 500, en fazla 5000) |
| `--all` | Hassas anahtar ön filtresini kullanmak yerine tüm satırları tarar |

Komut idempotent'tir ve eski bir yedek geri yüklendikten sonra yeniden çalıştırılmalıdır. Bir JSON payload'ı decode edilemezse sayılır, uyarıyla bildirilir ve değiştirilmeden bırakılır; hâlâ kimlik bilgisi içerebileceği için o satırı elle inceleyin.

## `encryption:key`

Adanmış bir `DATA_ENCRYPTION_KEY` üretir ve mevcut birincil anahtarı `DATA_ENCRYPTION_PREVIOUS_KEYS` içinde korur. Tam benimseme ve rotasyon anlatımı için [Veri Şifreleme](encryption.tr.md) belgesine bakın.

```bash
php artisan encryption:key
php artisan encryption:key --show
php artisan encryption:key --force
```

| Flag | Amaç |
| --- | --- |
| `--show` | Yeni üretilen anahtarı yazdırır, `.env`'e hiçbir şey yazmaz |
| `--force` | Ortam production gibi görünse bile çalışır |

Varsayılan bir çalıştırma sırasıyla: (1) mevcut birincil anahtarı çözer (`DATA_ENCRYPTION_KEY`, ya da ilk benimsemede `APP_KEY`); (2) yeni rastgele bir anahtar üretir; (3) eski birinciyi `DATA_ENCRYPTION_PREVIOUS_KEYS`'in başına ekler; (4) ancak ondan sonra yeni `DATA_ENCRYPTION_KEY`'i yazar. `APP_KEY`'e hiçbir yolda dokunulmaz. Komut, `--force` verilmeden production ortamında çalışmayı reddeder. Bitince `encryption:rekey`, ardından `encryption:health` çalıştırın; `DATA_ENCRYPTION_PREVIOUS_KEYS`'i yalnızca health OK raporladıktan sonra temizleyin.

## `encryption:rekey`

Ayarları ve 2FA secret'larını birincil şifreleme anahtarına yeniden şifreler. Bunu bir bakım penceresinde çalıştırmadan önce [sunucu taşıma runbook'u](server-migration-runbook.tr.md)'nu okuyun.

```bash
php artisan encryption:rekey
php artisan encryption:rekey --dry-run
php artisan encryption:rekey --only=settings
php artisan encryption:rekey --chunk=500
```

| Flag | Amaç |
| --- | --- |
| `--dry-run` | Her şifre çözme denemesini yapar, özetini yazdırır ama tek bir bayt yazmaz |
| `--only=<yüzey>` | Çalışmayı `settings` veya `two-factor` ile sınırlar (birleştirmek için virgülle ayırın) |
| `--chunk=<satır>` | Her turda okunan, kilitlenen ve yeniden yazılan satır sayısı (varsayılan 200, en fazla 2000) |

Her satır, çözümleme zincirindeki her anahtara sırayla denenir. Satırı çözen ilk anahtar, birincil anahtarla yeniden şifreler ve geri yazar; zaten birincil anahtarda olan bir satır yazılmadan atlanır. **Hiçbir** anahtarla çözülemeyen bir satır bayt bayt değiştirilmeden bırakılır, sayılır ve özet içinde kimliğiyle (`settings.key` / `users.id`) listelenir — asla null'lanmaz, silinmez veya üzerine yazılmaz.

## `encryption:health`

Her şifreli değerin hangi anahtara ihtiyaç duyduğunu ve `DATA_ENCRYPTION_PREVIOUS_KEYS`'in temizlenip temizlenemeyeceğini raporlar. Salt-okunur — hiçbir anahtar materyali asla yazdırılmaz.

```bash
php artisan encryption:health
php artisan encryption:health --json
```

- `--json`, `sk:doctor --json` ile aynı şekli taklit eden makine okunabilir bir rapor üretir

Kararlar: her şey birincil anahtardaysa ve çözülemeyen yoksa → "`DATA_ENCRYPTION_PREVIOUS_KEYS` temizlenebilir" (çıkış `0`); hâlâ önceki bir anahtarda satır varsa → "Önce `encryption:rekey` çalıştırın; `DATA_ENCRYPTION_PREVIOUS_KEYS`'i TEMİZLEMEYİN"; çözülemeyen satır varsa → en yüksek sesli hata, etkilenen satırları ve eksik anahtarı adlandırır. Tam taranamayan bir yüzey (eksik tablo, sorgu hatası) kararı yalnızca aşağı çeker, asla yukarı çekmez.

## `file-manager:purge-trash`

Belirlenen yaştan eski soft-delete edilmiş Dosya Yöneticisi öğelerini kalıcı olarak siler.

```bash
php artisan file-manager:purge-trash
php artisan file-manager:purge-trash --days=30
php artisan file-manager:purge-trash --chunk=1000
```

Varsayılan süre 7 gündür. Komut yalnız File Manager media kayıtlarını (`collection_name = files`) ve çöpteki klasörleri hedefler; avatar, logo, editor upload veya diğer MediaLibrary collection'larına dokunmaz. Paketle gelen `routes/console.php` komutu `withoutOverlapping()` ile günlük schedule eder.

- `--chunk=<n>` (varsayılan 500, aralık 1–5000) her turda yüklenen satır sayısıdır; satırlar `chunkById` ile gezilir, böylece çöpün tamamı belleğe alınmaz.
- Çalıştırma bir **cache lock** alır (`starter-kit:file-manager:purge-trash`, 1 saat TTL); böylece iki scheduler — ya da cron ile yarışan bir operatör — aynı satırları iki `forceDelete()` çağrısına veremez. Eşzamanlı ikinci çalıştırma uyarı verip purge yapmadan `0` ile çıkar.
- Başarısız tek bir öğe çalıştırmayı durdurmaz; kalan öğeler yine işlenir ve geride bir şey kaldıysa komut **sıfırdan farklı bir kodla çıkar**.
