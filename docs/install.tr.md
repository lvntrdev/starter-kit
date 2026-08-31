# Kurulum

> **Aktif Geliştirme Uyarısı**
>
> Bu depo (repository) sürekli bir değişim içerisindedir. Projenin stabilitesi henüz tam olarak sağlanmamıştır. Kullanırken lütfen aşağıdaki noktaları göz önünde bulundurun:
>
> 1. **Kod Değişiklikleri:** Ana dizin yapısı veya çekirdek sınıflar radikal şekilde değişebilir.
> 2. **Güncelleme Süreci:** Güncellemeler her zaman otomatik bir geçiş (migration) sunmayabilir. Güncelleme sonrası README veya CHANGELOG dosyalarını kontrol ederek elle müdahale etmeniz gerekebilir.
> 3. **Risk:** Yapılan değişiklikler mevcut projenizde veri kaybına veya hatalara yol açabilir.

Bu rehber, sıfır bir proje için önerilen kurulum akışını anlatır.

> **Boş bir Laravel kurulumundan başlayın.** Bu paketi kurmadan önce `php artisan install:inertia`, `install:api`, Breeze, Jetstream veya başka bir starter preset **çalıştırmayın**. Preset'ler bu starter kit'in de yayınladığı controller, route, sayfa ve layout'ları oluşturur — installer bunları tespit edemediği için kit'in kendi dosyalarının yanında yetim "ölü kod" olarak kalırlar.
>
> Önerilen akış:
>
> ```bash
> composer create-project laravel/laravel my-app
> cd my-app
> composer require lvntr/laravel-starter-kit:^13.6
> php artisan sk:install
> ```
>
> **Başlamadan önce `php -v` çıktısının 8.4 veya üzeri olduğunu doğrulayın.**
> `composer create-project laravel/laravel` yalnızca PHP 8.3 ister; bu yüzden
> 8.3'te sorunsuz tamamlanır ve sizi bu kit'in tabanının bir adım altında
> bırakır. Paketi gevşek bir `:^13.0` yerine `:^13.6` ile ekleyin — gevşek
> constraint, Composer'ın gerçek engeli bildirmek yerine PHP 8.3'e uyan eski bir
> sürümü sessizce kurmasına yol açar (ardından `composer update` "nothing to
> update" der). Kurulum beklenmedik bir sürüme düşerse
> `composer why-not lvntr/laravel-starter-kit 13.6.14` komutu engeli gösterir.

## Gereksinimler

| Gereksinim | Sürüm           |
| ---------- | --------------- |
| PHP        | 8.4+            |
| Laravel    | 13              |
| Node.js    | 20.19+             |
| Veritabanı | MySQL / MariaDB |

## 1. Projeyi Hazırlayın

Başlamadan önce projede çalışan bir veritabanı bağlantısı ve geçerli bir `.env` dosyası olduğundan emin olun. Temel ayarları önceden girin:

```env
APP_NAME="Uygulamam"
APP_URL=https://uygulamam.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uygulamam
DB_USERNAME=root
DB_PASSWORD=
```

### Dikkat Edilmesi Gereken Env Değişkenleri

Installer, yeni kurulumların gözden geçirmesi gereken birkaç anahtar taşıyan başlangıç `.env.example` yazar:

```env
# Timestamp saklama UTC olarak kalmalıdır. Sitenin gösterim fallback'i için
# APP_DISPLAY_TIMEZONE kullanın; kullanıcılar profilinden override edebilir.
APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=UTC

# Log seviyesi — local dev için 'debug' uygundur; production 'error' veya 'warning' göndermeli.
LOG_LEVEL=error

# Route adından izni türetilemeyen bir isteği controller'a gate'siz ulaştırmak
# yerine reddeder. YENİ bir proje için bilerek false yazılır: geriye dönük
# korunması gereken eski bir route yoktur, dolayısıyla gate'siz kalan bir
# route'unuz production'da değil geliştirme sırasında yakalanır.
STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false

# Cloudflare Turnstile (bot / captcha). TURNSTILE_ENABLED=false iken
# `turnstile` middleware'i no-op olduğu için lokal olarak anahtarları boş bırakmak güvenli.
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=

# Session sertleştirme — ikisinin de default'u 'true'. Production'da açık tutun.
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# APP_KEY'den bağımsız, hassas ayarlar (settings.value) ve 2FA secret'ları için
# adanmış anahtar. `php artisan key:generate` bunlara hiç dokunmaz. İlk kurulumda
# otomatik üretilir; her sunucu taşımasında .env ile birlikte taşınmalıdır
# (bkz. docs/server-migration-runbook.tr.md).
DATA_ENCRYPTION_KEY=
DATA_ENCRYPTION_PREVIOUS_KEYS=

# Passport OAuth2 anahtarları — production için önerilen desen, anahtarları
# storage/oauth-*.key dosyalarına commit etmek yerine env üzerinden yüklemek.
# Bir kez `php artisan passport:keys` çalıştırın, üretilen string'leri bu env
# değişkenlerine taşıyın, sonra dosyaları silin.
# PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
# PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
```

`STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` yalnızca **yeni** bir `.env` dosyasına yazılır. Paketi güncelleyen mevcut bir uygulama izin veren varsayılanla kalır; hiçbir sürüm bu değeri sizin yerinize çevirmez — bkz. [Yükseltme Notları](UPGRADE.tr.md#çözülemeyen-routelarda-fail-closed-mevcut-kurulum-için-opt-in). Temiz kurulumdan sonra bir route'unuz 403 dönmeye başlarsa `php artisan sk:doctor --only=unresolved-routes` izni türetilemeyen tüm route'ları listeler; route'a `resource.action` biçiminde bir isim verin, bilerek izinsiz bırakılacaksa `config/starter-kit.php` içindeki `permissions.unrestricted_routes` listesine ekleyin ya da düzeltene kadar anahtarı `true` yapın.

`APP_TIMEZONE` değerini sitenin bölgesel saat dilimine ayarlamayın: bu değişken Laravel'in saklama saat dilimini yönetir. Bunun yerine `APP_DISPLAY_TIMEZONE` kullanın veya kurulumdan sonra **Ayarlar → Genel** bölümünden site fallback'ini seçin. Kullanıcı override'ları ve tam çözüm zinciri için [Saat Dilimleri](timezone.tr.md) belgesine bakın.

`DATA_ENCRYPTION_KEY` ve `DATA_ENCRYPTION_PREVIOUS_KEYS`, hassas ayar değerlerini ve 2FA secret'larını `APP_KEY`'den bağımsız olarak korur — temiz bir kurulum anahtarı otomatik üretir, herhangi bir işlem gerekmez. Önemli olan bundan sonraki `.env` disiplinidir: bir sunucu taşıması her iki anahtarı da `.env`'in geri kalanıyla birlikte taşımalı, `php artisan key:generate` asla bunun yerine geçmemelidir. Tam anahtar-çözümleme sözleşmesi ve rotasyon komutları için [Veri Şifreleme](encryption.tr.md) belgesine, yeni bir sunucuya geçmeden önce [sunucu taşıma runbook'u](server-migration-runbook.tr.md)'na bakın.

## 2. Paketi Ekleyin

```bash
composer require lvntr/laravel-starter-kit:^13.6
```

## 3. Kurulum Komutunu Çalıştırın

```bash
php artisan sk:install
```

> **`sk:install` bir ilk-kurulum komutudur, onarım aracı değildir.** Kit'in henüz kurulmadığı bir projede bir kez çalıştırın. Kurulu bir uygulamada tekrar çalıştırmak güvenli **değildir**: yalnızca `lang/` korunabilir, yayınlanan diğer her yol `--force` olmadan da üzerine yazılır ve hash kaydı yalnızca sildiğiniz bir dosyayı korur — düzenlediğinizi değil. Kayıt, git tarafından yok sayılan `storage/starter-kit/hashes.json` altında durur; stateless bir deploy onu kaybederse komut uygulamayı ilk kurulum sayar ve mevcut `.env` dosyanızın üzerine `.env.example` kopyalar. Kurulu bir uygulamayı değiştirmek için [`sk:update`](update.tr.md) ya da `sk:publish --tag=<alan>` kullanın.

Herhangi bir dosyaya dokunmadan önce installer bir **preflight** kontrolü çalıştırır (Node.js sürümü — Node eksikse ya da Vite 7 motor tabanı olan 20.19'dan eskiyse uyarı verir ve npm adımının kendi kendine düşmesine izin verir; asla hard-fail olmaz) ve önceki yarıda kalmış bir çalışmadan **checkpoint** varsa yükler (`storage/starter-kit/install-progress.json`). Bir adım hata fırlatırsa installer ham stack trace yerine somut bir mesajla durur ("Step failed: `<adım>` — sorunu düzelt, sonra `sk:install --resume` çalıştır"); tamamlanmış adımlar checkpoint'e yazıldığından `--resume` onları atlayıp kaldığı yerden devam eder. Kurulum başarıyla bitince progress dosyası otomatik silinir.

Sihirbaz ardından her adımda sizinle interaktif olarak ilerler:

| Adım | Ne yapar                                                                                             |
| ---- | ---------------------------------------------------------------------------------------------------- |
| 1    | Uygulama iskeletini yayınlar (Controller, Model, Route, Vue sayfaları, Enum, Provider, vb.)          |
| 2    | `package.json` bağımlılıklarını birleştirir                                                          |
| 3    | Taze yayınlanan `.env.example`'dan `.env` dosyasını doldurur, sonra boşsa `APP_KEY` üretir            |
| 4    | Veritabanı bağlantısını yapılandırır (sürücü, host, port, veritabanı, kimlik bilgileri) — `--no-interaction`'da atlanır |
| 5    | Çakışan varsayılan Laravel dosyalarını siler (`vite.config.js`, `welcome.blade.php`, vb.)            |
| 6    | Kit'in `.gitignore` girdilerini projenin mevcut dosyasıyla birleştirir                                |
| 7    | Config dosyalarını yayınlar ve enjekte eder (`APP_DISPLAY_TIMEZONE` tabanlı `display_timezone` dahil `app.php`; mevcut MySQL/MariaDB bağlantı dizilerini `+00:00` değerine sabitleyen `database.php`; `filesystems.php`; Turnstile için `services.php`; `media-library.php`), `bootstrap/app.php`'yi bağlar, service provider'ları kaydeder ve custom-helpers autoload girdisini ekler |
| 8    | `User` + `Role` domain runtime'ını `app/Domain/` altına eject eder (`--without-eject` verildiğinde ya da `storage/starter-kit/hashes.json` zaten mevcutsa atlanır) |
| 9    | Composer autoload'u yeniden oluşturur                                                                |
| 10   | Veritabanı migration'larını çalıştırır — veritabanına ulaşılamıyorsa uyarıyla atlanır; bağlantıyı düzelt, `--resume` ile tekrar çalıştır |
| 11   | Seeder'ları çalıştırır (Roller, Yetkiler, Tanımlar, Ayarlar)                                         |
| 12   | `config/permission-resources.php`'den yetkileri seed eder                                            |
| 13   | Passport şifreleme anahtarlarını oluşturur                                                           |
| 14   | Varsayılan admin kullanıcısı oluşturur (`admin@lvntr.dev` / sonunda ekrana basılan rastgele parola)   |
| 15   | npm bağımlılıklarını yükler ve frontend'i derler                                                     |
| 16   | Uygulama anahtarını sonlandırır ve `sk:update` takibi için stub hash'lerini kaydeder                 |

Config adımında `sk:install`, `config/database.php` içindeki mevcut `mysql` ve `mariadb` dizilerine literal `'timezone' => '+00:00'` sözleşmesini ekler. Consumer'ın tanımladığı bir `timezone` değerinin üzerine yazmaz, eksik bir bağlantı oluşturmaz; `sqlite`, `pgsql` veya `sqlsrv` bağlantılarına dokunmaz.

Sıfır kurulumlarda veri dönüşümü gerekmez. Consumer `sk:install` komutunu zaten veri barındıran bir veritabanına yöneltebileceği için, önce varsayılan MySQL/MariaDB bağlantısının UTC dışı bir oturumda veri taşıyıp taşımadığını kontrol eder — taşıyorsa pin adımını **atlar** ve bu durumu onay kapısıyla ele alan `sk:upgrade` komutunu, [tek seferlik dönüşüm rehberini](timezone.tr.md#mevcut-veriler-için-tek-seferlik-dönüşüm) okuduktan sonra çalıştırmanızı söyler. Ulaşılamayan bir veritabanı sıfır kurulum gibi değerlendirilir ve adımı bloke etmez.

### Varsayılan domain eject'i (User + Role)

Sıfır bir kurulumda installer, `User` ve `Role` domain runtime sınıflarını otomatik olarak `app/Domain/User/` ve `app/Domain/Role/` altına eject eder. Bu iki domain gerçek projelerde en çok özelleştirilen alanlardır; bu nedenle kurulumdan itibaren doğrudan uygulamanıza aittir.

**Bu ne anlama gelir:**

- Backend sınıfları (Actions, DTOs, Queries, Events, Listeners) `App\Domain\` namespace'iyle `app/Domain/{User,Role}/` altına kopyalanır.
- `DomainServiceProvider`, audit log kesintisiz çalışsın diye ilgili `Event::listen` binding'lerini alır.
- Bu noktadan itibaren **kit bu domain'lere `composer update` aracılığıyla runtime güncellemesi göndermez** — dosyalar sizin sorumluluğunuza geçer. Bu, manuel `sk:eject` çağrısıyla birebir aynı takastır.

**Geri alma veya devre dışı bırakma:**

Kurulum sonrası eject'i geri almak için `app/Domain/User/` ve `app/Domain/Role/` dizinlerini silin, `app/Providers/DomainServiceProvider.php` içindeki enjekte edilen `Event::listen` satırlarını kaldırın ve `composer dump-autoload` çalıştırın. Vendor runtime ve alias çözümü otomatik devreye girer.

Sıfır bir kurulumda eject adımını tamamen atlamak için `--without-eject` kullanın:

```bash
php artisan sk:install --without-eject
```

Domain'ler vendor'da kalır ve `class_alias` aracılığıyla çözülür; eject öncesi davranışla birebir aynıdır. İstediğiniz zaman `sk:eject User` / `sk:eject Role` komutlarını manuel olarak çalıştırabilirsiniz.

### Yararlı Flag'ler

```bash
php artisan sk:install --force
php artisan sk:install --no-interaction
php artisan sk:install --without-ai-skill
php artisan sk:install --without-eject
php artisan sk:install --resume
```

- `--force` mevcut yayınlanabilir dosyaların üzerine yazar
- `--no-interaction` CI veya script tabanlı kurulumlar için uygundur; tüm varsayılanları otomatik olarak kabul eder; admin parolası, girecek bir operatör olmadığından her zaman taze bir rastgele değerdir (sonunda ekrana basılır)
- `--without-ai-skill` Lvntr Starter Kit AI skill'lerinin yayınlanmasını tamamen atlar — hem Claude Code kopyaları (`.claude/skills/`) hem de Codex aynası (`.codex/skills/`). Kit'in skill bundle'ını ne Claude Code ne Codex ile kullanan consumer'lar için
- `--without-eject` varsayılan `User` ve `Role` domain eject'ini atlar; runtime vendor'da kalır ve `class_alias` ile çözülür
- `--resume` yarıda kalmış bir kurulumu kaldığı yerden devam ettirir: `storage/starter-kit/install-progress.json`'a checkpoint'lenmiş adımlar atlanır, çalışma başarısız olan adımdan devam eder. Önceden bir checkpoint yoksa uyarıyla birlikte tam bir kurulum çalıştırır.

## 4. Frontend Asset'lerini Derleyin

Kurulum sırasında asset adımını atladıysanız şunları çalıştırın:

```bash
npm install
npm run build
```

Lokal geliştirme için:

```bash
composer dev
```

## 5. Kurulumu Doğrulayın

Kurulumdan sonra şu alanları kontrol edin:

- web giriş ekranı (`admin@lvntr.dev` ve installer'ın bastığı parola, ya da interaktif kurulumda girdiğiniz bilgilerle giriş yapın)
- register ve forgot-password sayfaları; etkinse Turnstile widget'ı
- dashboard erişimi
- kullanıcı ve rol yönetimi sayfaları
- profil güvenliği sayfası (şifre, 2FA, tarayıcı oturumları, avatar)
- ayarlar sayfasındaki sekmeler: General, Auth, Mail, Storage, File Manager, API Integrations, API Clients, API Tokens, System Health
- dosya yöneticisi
- `/api/v1/auth/login` ve `/api/v1/auth/me`

## 6. Kurulum Sonrası Modül Sahipliği

`sk:install` yalnızca kurulumdan itibaren özelleştirmeniz beklenen modülleri kopyalar. Mantığı proje özelinde değişme olasılığı düşük olan davranış modülleri, vendor paketinden çalışır — uygulamanıza herhangi bir dosya üretmezler.

| Modül | Uygulamanıza kurulan dosyalar | Vendor'da çalışır (uygulama kopyası yok) |
|---|---|---|
| Users, Roles | Controller, FormRequest, Vue sayfaları, route'lar, Model'lar, Policy'ler | — |
| Dashboard, Auth ekranları, Profile | Controller, FormRequest, Vue sayfaları, route'lar | — |
| Files (Dosya Yöneticisi) | — | Vue sayfaları + controller |
| Logs | — | Vue sayfaları + controller |
| Activity Logs | — | Vue sayfaları + controller |
| API Routes | — | Vue sayfaları + controller |
| Settings | — | Vue sayfaları + controller |

**Vendor'da çalışan modüller**, `app.ts` vendor-fallback sayfa yükleyicisi tarafından çözülür — uygulamanızda herhangi bir dosya bulunmasına gerek yoktur. Derin özelleştirme için bir vendor-first modülün tam sahipliğini almak üzere `sk:eject` çalıştırın:

```bash
php artisan sk:eject Logs             # controller + FormRequests + Vue sayfalarını uygulamanıza kopyalar
php artisan sk:eject Logs --dry-run   # önce önizleyin
php artisan sk:eject Logs --no-vue    # yalnızca backend
php artisan sk:eject Files            # yalnızca Vue sayfaları (Files backend her zaman vendor'da kalır)
```

Eject sonrası modülün dosyaları uygulamanızda bulunur ve `sk:update` bunları sizin dosyalarınız olarak işler — upstream güncellemeler artık otomatik ulaşmaz.

## 7. İsteğe Bağlı Yayınlama

Paket birçok varlığı varsayılan olarak kendi içinde tutar. Proje seviyesinde özelleştirme gerektiğinde yayınlayın:

```bash
php artisan sk:publish
php artisan sk:publish --tag=components
php artisan sk:publish --tag=composables
php artisan sk:publish --tag=filemanager
php artisan sk:publish --tag=lang
php artisan sk:publish --tag=config
```

## Ek Yapılandırma

İki `config/starter-kit.php` anahtarı interaktif installer'ın bir parçası değildir ama kit davranışını değiştirir — kurulumdan önce env üzerinden set edin veya yayınlanmış config dosyasını override edin:

| Config anahtarı | Env değişkeni | Varsayılan | Etkisi |
|---|---|---|---|
| `app_namespace` | `STARTER_KIT_APP_NAMESPACE` | `App` | Yalnızca `sk:publish` tarafından okunur (`sk:install`'ın ana scaffolding adımı tarafından değil): varsayılan olmayan bir değere set edildiğinde, bu komutun kopyaladığı `.php` dosyalarındaki (`--tag=config` ile `config/starter-kit.php`, `--tag=helpers` ile `app/Helpers/sk-helpers.php`) `namespace App\…` / `use App\…` / `App\…` referanslarını yapılandırılan namespace'e yeniden yazar. `sk:install`'ın kendisinin kopyaladığı dosyalar olduğu gibi kopyalanır — varsayılan olmayan bir uygulama namespace'i `sk:install` sonrasında hâlâ manuel düzenleme gerektirir. |
| `strict_models` | `STARTER_KIT_STRICT_MODELS` | `true` | `true` olduğunda `StarterKitServiceProvider`, Eloquent'in `Model::shouldBeStrict()` modunu production dışında (local/staging/testing) etkinleştirir — lazy-loading, eksik bir attribute'a erişme ve fillable olmayan bir mass-assignment'ı sessizce yok sayma hepsi throw eder, böylece bug'lar erken ortaya çıkar. Bu ayardan bağımsız olarak production trafiği asla etkilenmez. Bu guard'lara takılan legacy bir şema entegre ederken olduğu gibi, tamamen opt-out olmak için `false` yapın. |

## Veritabanını Sıfırlama (site:install)

Geliştirme sırasında `site:install` komutu tüm tabloları silip sıfırdan kurar:

```bash
php artisan site:install
```

Bu komut:

1. Onay için hedef veritabanı ve ortam detaylarını gösterir
2. `migrate:fresh` çalıştırır (tüm tabloları silip migration'ları tekrar çalıştırır)
3. Tüm seeder'ları çalıştırır (`database/seeders/` altındaki `_` ile başlayan dosyalar)
4. Passport anahtarlarını oluşturur
5. Varsayılan admin kullanıcısını oluşturur

**Güvenlik korumaları:**

- Sadece `local` ve `setup` ortamlarında çalışır
- `prod` veya `production` içeren ortamlarda kalıcı olarak engellenir
- Devam etmeden önce açık onay gerektirir

> **Not:** `site:install` bir stub dosyası olarak yayınlanır. Özelleştirirseniz (örneğin, özel seeder eklerseniz veya admin bilgilerini değiştirirseniz), `sk:update` komutu değişikliklerinizi tespit eder ve güncelleme sırasında bu dosyayı atlar.

## Paketi Güncelleme

Yeni bir sürüm yayınlandığında:

```bash
# 1. Composer paketini güncelleyin
composer update lvntr/laravel-starter-kit

# 2. Uygulama dosyalarını senkronize edin
php artisan sk:update
```

Güncelleme komutu, paket güncellemelerini özelleştirmelerinizle güvenli şekilde birleştirmek için hash tabanlı izleme sistemi kullanır:

| Dosya kategorisi                                                                                                          | Davranış                                                                                             |
| ------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| **Runtime (vendor)** — `Domain/Shared/`, Trait'ler, Middleware, helper'lar, `ApiResponse`, FileManager katmanı            | `vendor/` altında çalışır — `composer update` ile otomatik güncellenir; `sk:update` kopyalamaz       |
| **Hash takipli stub'lar** — auth/layout Vue bileşenleri, user/rol/ayar domain iskeleti                                    | Paket sürümü değiştiğinde diff bildirimi yapılır; lokal hash hâlâ eşleşiyorsa uygulanır              |
| **Kullanıcı tarafından değiştirilebilir dosyalar** (Controller, Model, Sayfa, Route, `SiteInstallCommand`)                | Sadece son kurulum/güncellemeden beri değiştirmediyseniz güncellenir                                 |
| **Asla güncellenmeyen dosyalar** (`config/permission-resources.php`)                                                      | Bir kez kurulur, bir daha dokunulmaz                                                                 |
| **Sizin özel domain'leriniz**                                                                                             | Asla dokunulmaz                                                                                      |
| **Paketten gelen yeni dosyalar**                                                                                          | Otomatik olarak eklenir                                                                              |
| **Kullanım dışı dosyalar**                                                                                                | Otomatik olarak silinir                                                                              |

```bash
# Hiçbir değişiklik yapmadan nelerin değişeceğini önizleyin
php artisan sk:update --dry-run

# Her şeyi zorla güncelle (özelleştirmelerinizin üzerine yazar)
php artisan sk:update --force
```

## Laravel 12'den Yükseltme

Mevcut bir Starter Kit projeniz Laravel 12 üzerindeyse:

```bash
# 1. composer.json'da Laravel 13 gereksinimini güncelleyin
composer require laravel/framework:^13.0 lvntr/laravel-starter-kit:^13.6 -W

# 2. Yükseltme sihirbazını çalıştırın
php artisan sk:upgrade
```

Yükseltme komutu Laravel 13+, Starter Kit v13+, PHP 8.4+ doğrular; stub'ları senkronize eder; cache'leri temizler; yeni migration'ları çalıştırır (isteğe bağlı); rolleri ve yetkileri yeniden seed'ler (isteğe bağlı); ve frontend'i yeniden derler.

```bash
php artisan sk:upgrade --force       # onay istemlerini atla
php artisan sk:upgrade --skip-build  # npm install / npm run build adımını atla
```

## Tüm Mevcut Komutlar

| Komut              | Açıklama                                                        |
| ------------------ | --------------------------------------------------------------- |
| `sk:install`       | Tam kurulum sihirbazı                                           |
| `sk:update`        | Kullanıcı değişikliklerini koruyarak paket dosyalarını güncelle |
| `sk:upgrade`       | Önceki Laravel sürümünden yükseltme                             |
| `sk:publish`       | Özelleştirme için isteğe bağlı varlıkları yayınla               |
| `site:install`     | Veritabanını sıfırla ve varsayılan verilerle yeniden kur        |
| `make:sk-domain`   | İnteraktif olarak eksiksiz bir DDD domain'i oluştur             |
| `remove:sk-domain` | Bir domain'i ve tüm dosyalarını kaldır                          |
| `env:sync`         | `.env` anahtarlarını `.env.example` ile senkronize et           |

## Sorun Giderme

**Kurulum sonrası Vite manifest hatası:**

```bash
npm run build
# veya dev sunucusunu başlatın
npm run dev
```

**Frontend değişiklikleri yansımıyorsa:**

```bash
npm run dev
# veya yeniden derleyin
npm run build
```

**Kurulum sonrası sınıflar bulunamıyorsa:**

```bash
composer dump-autoload
```

**Passport anahtarları eksikse:**

```bash
php artisan passport:keys --force
```

**Deploy sonrası `php artisan tinker` bulunamıyorsa:**

`laravel/tinker` artık `require-dev` altında — production build'leri `composer install --no-dev` ile çalıştığında Tinker kurulmaz. Bu bilinçli bir tercih. Sunucuda tinker'a ihtiyacın varsa `composer require laravel/tinker` (require-dev dışında) ile açıkça kur.

İlgili dökümanlar:

- [update.tr.md](./update.tr.md)
- [artisan-commands.tr.md](./artisan-commands.tr.md)
- [ui-components.tr.md](./ui-components.tr.md)
