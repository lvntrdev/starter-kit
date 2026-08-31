# Güncelleme

Bu rehber, mevcut bir projede starter kit'i en güvenli şekilde nasıl güncelleyeceğinizi anlatır.

> **Hardening / güvenlik sürümleri:** Sürüm notları **publish edilmiş dosyalara** (yani `sk:install`'ın uygulamanıza kopyaladığı controller, request, policy, composable, config dosyalarına) dokunan düzenlemelerden bahsediyorsa, `sk:update` bunları lokal olarak değiştirdiyseniz (yaygın durum budur) **üzerine yazmaz**. Bu tür sürümler için [UPGRADE.tr.md](./UPGRADE.tr.md) rehberini izleyin — elle uygulamanız gereken diff formatında patch listesini ve smoke-test checklist'ini içerir.
>
> Ayrım bilinçli: `composer update` katmanı paket-içi kodu (`vendor/lvntr/laravel-starter-kit/src/`) taşır, UPGRADE rehberi ise uygulamanızın içindeki kopya katmanı taşır.

> **v13.4.1:** Bu sürüm, publish edilmiş dosya patch'lerine ek olarak üç adet kurulum-zamanı düzeltmesi de getiriyor (OAuth UUID migration'ları, Postman ayar tablosu migration'ı, Passport personal access client sağlaması) — mevcut kurulumların bir kez çalıştırması gereken komutlar için [UPGRADE.tr.md §7](./UPGRADE.tr.md) bölümüne bakın.

## Önerilen Akış

1. Mevcut çalışmanızı commit edin.
2. Paket güncellemesini önizleyin.
3. Paket güncellemesini uygulayın.
4. Migration, env senkronizasyonu ve asset build işlemlerini çalıştırın. (v13.4.1: `oauth_*` migration'larını da yeniden çalıştırın — bkz. [UPGRADE.tr.md §7.1](./UPGRADE.tr.md).)
5. Yetkileri, route'ları, auth/settings ekranlarını ve kritik sayfaları tekrar kontrol edin.

## 1. Composer Paketini Güncelleyin

```bash
composer update lvntr/laravel-starter-kit
```

## 2. Önce Değişiklikleri Önizleyin

```bash
php artisan sk:update --dry-run
```

Projede özelleştirilmiş controller, route, sayfa veya config kararları varsa gerçek güncellemeden önce `--dry-run` kullanın.

## 3. Güncellemeyi Uygulayın

```bash
php artisan sk:update
```

### `sk:update` Ne Yapar

- runtime kod (`Domain/Shared/`, Trait'ler, Middleware, helper'lar, `ApiResponse`, FileManager katmanı) v13.5.0'dan itibaren `vendor/` altında çalışıyor — `composer update` yeterli, `sk:update` bu dosyaları kopyalamıyor
- vendor'a taşınan eski app-tarafı dosyaları kaldırır
- vendor-first davranış modüllerini (Files/Logs/ActivityLogs/ApiRoutes/Settings) göç ettirir — aşağıya bakın
- hash takipli stub değişikliklerini bildirir (auth/layout Vue bileşenleri, user/rol/ayar domain iskeleti); lokal hash hâlâ eşleşiyorsa uygular
- kullanıcı tarafından değiştirilebilen dosyaları yalnızca lokal olarak değiştirilmemişse günceller
- izlenmeyen dosyalar için nasıl davranılacağını sorar
- paketle gelen yeni dosyaları ekler
- eksik filesystem ve media library config parçalarını enjekte eder
- yeni migration'ları isteğe bağlı olarak çalıştırabilir

### Vendor-first davranış modülü göçü (v13.6.0+)

Beş davranış modülü — **Files, Logs, ActivityLogs, ApiRoutes, Settings** — controller'larını, FormRequest'lerini ve Vue admin sayfalarını vendor paketinden çalıştırır. `sk:update`, mevcut uygulama kopyalarını hash koruması ve `app.ts` koruması altında göç ettirir.

**Kaldırma kararı modül grubu başına, iki bağımsız katmanda verilir:**

- `php` katmanı — controller + FormRequest dizin ağacı. Sunucu tarafı alias bridge üzerinden çözülür; `app.ts` durumundan bağımsız olarak göç edebilir.
- `vue` katmanı — Inertia sayfa ağacı. `app.ts`'in `@lvntr/pages` vendor-fallback glob'unu içermesini gerektirir. Marker yoksa, siz `app.ts`'i güncelleyip `sk:update`'i yeniden çalıştırana dek Vue grupları uyarıyla yerinde bırakılır.

**Grup atomikliği:** bir modülün katmanındaki herhangi bir dosya kullanıcı tarafından değiştirilmişse veya izlenmiyorsa, o modülün tüm katmanı korunur. Yarım silinmiş modül hiçbir zaman oluşturulmaz.

#### Senaryo A — değiştirilmemiş kurulum

Beş modülün tamamı otomatik olarak göç eder:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
npm run build
```

#### Senaryo B — bir veya daha fazla modülde değiştirilmiş dosya

`sk:update` korunan modülleri raporlar. Özelleştirilmiş dosyalarınız değişmeden çalışmaya devam eder. Vendor'a göç etmiş bir modülün açıkça sahipliğini almak için `sk:eject` çalıştırın:

```bash
php artisan sk:eject Logs             # controller + FormRequests + Vue sayfalarını uygulamanıza kopyalar
php artisan sk:eject Logs --dry-run   # önce önizleyin
php artisan sk:eject Logs --no-vue    # yalnızca backend
php artisan sk:eject Files            # yalnızca Vue sayfaları (Files backend her zaman vendor'da kalır)
```

Eject sonrası `sk:update` bu dosyaları consumer'a ait olarak işaretler ve bir daha kaldırmaz.

#### Senaryo C — v13.6.0+ ile sıfır kurulum

Hiçbir işlem gerekmez. `sk:install` beş vendor-first modülü kopyalamaz. Bunlar kurulumdan itibaren vendor'dan çalışır.

## 4. Zorlayıcı Mod

```bash
php artisan sk:update --force
```

Bunu yalnızca paket dosyalarının yerel değişikliklerinizin üzerine bilinçli şekilde yazmasını istiyorsanız kullanın.

## 5. Güncelleme Sonrası Kontrol Listesi

Başarılı güncellemeden sonra şunları çalıştırın:

```bash
npm install
npm run build
php artisan migrate
php artisan env:sync
```

Ardından güncellemenin, matrisinizde henüz tanımlı olmayan izinler bekleyip beklemediğini kontrol edin:

```bash
php artisan sk:doctor --only=permission-matrix
```

Permission kaynakları veya roller değiştiyse — ya da yukarıdaki kontrol bir şey listelediyse — ayrıca şunu çalıştırın:

```bash
php artisan sk:seed-permissions --fresh
```

Ardından, hiçbir izin kontrolünden geçmeden controller'a ulaşan route'ları listeleyin:

```bash
php artisan sk:doctor --only=unresolved-routes
```

Bu kontrol, `CheckResourcePermission` bir izin türetemediği her route için FAIL raporlar. Böyle bir route **bugün geçiyor** — middleware yalnızca kısıtlanmış bir uyarı logluyor — ve siz aksini söyleyene kadar geçmeye devam edecek — **hiçbir sürüm mevcut bir kurulumda bunu 403'e çevirmiyor**. Bu kontrol temiz çıktığında `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` vererek opt-in yapın; yeni kurulan bir proje bu satırla zaten geliyor. Kitin kendi gönderdiği route'lar paket içinde zaten çözülmüş durumda; bu kontrolün listelediği şey sizin kendi route'larınız ve kendi kopyanızda adını değiştirdiğiniz kit route'ları. Sıralı düzeltme yolu için [UPGRADE.tr.md](UPGRADE.tr.md) belgesine bakın.

Güncellemeyle yeni ayar grupları veya auth davranışları geldiyse şu ekranları bir kez açıp doğrulayın:

- Ayarlar -> Auth
- Ayarlar -> Turnstile
- Ayarlar -> File Manager
- Profil güvenlik sekmeleri

Bu sürüm, `APP_KEY`'den bağımsız olarak hassas ayarlar ve 2FA secret'ları için adanmış bir `DATA_ENCRYPTION_KEY` ekliyor. **Mevcut bir kurulumun hiçbir işlem yapması gerekmiyor** — `DATA_ENCRYPTION_KEY` boş kalır, şifreleme tıpkı önceki gibi `APP_KEY` kullanmaya devam eder ve `composer update` / `sk:update` benimsemeyi zorlamaz. Adanmış anahtarı benimsemek opt-in'dir: `encryption:key` → `encryption:rekey` → `encryption:health` anlatımı için [Veri Şifreleme](encryption.tr.md) belgesine, bu kurulumu yeni bir sunucuya taşımak üzereyseniz [sunucu taşıma runbook'u](server-migration-runbook.tr.md)'na bakın.

## Dosya Güncelleme Stratejisi Özeti

- Paket sahipli çekirdek yollar otomatik yenilenir — ancak yalnızca kopyanız kurulum/güncelleme anında kaydedilen hash ile hâlâ eşleşiyorsa. Buradaki tek girdi `app/Enums/PermissionEnum.php` ve ona eklediğiniz bir yetenek case'i ezilmek yerine korunur ve raporlanır. Paketin yeni case'lerini elle birleştirin (`vendor/lvntr/laravel-starter-kit/stubs/` altındaki aynı göreli yol ile karşılaştırın) ya da `--force` ile paket sürümünü alıp düzenlemelerinizi bırakın.
- Özelleştirilebilir dosyalar değişmediyse güncellenir, aksi halde korunur.
- `config/permission-resources.php` kullanıcıya ait bir dosya olarak kabul edilir ve asla yazılmaz. Bunun diğer yüzü: paketin eklediği kaynak ve yetenekler kendiliğinden gelmez. `php artisan sk:doctor --only=permission-matrix` matrisinizde eksik olanları raporlar.
- Paketle gelen yeni dosyalar otomatik eklenir.

## Özelleştirilmiş Bir Dosyayı Geri Alma

Ayrı bir `sk:rollback` komutu yok — geri alma, dosyayı barındıran tag üzerinde `sk:publish --force` ile yapılır. Bu bilinçli bir tercih: kod yolu sıfır kurulumla aynı kalır, geri alma gölge state'e güvenmez.

```bash
# Kullanılabilir tag'leri listele
php artisan sk:publish --help

# Tek bir özelleştirilebilir alanı (örn. sadece FormBuilder) paket versiyonuna sıfırla
php artisan sk:publish --tag=form --force

# Önce izole bir dizine publish edip farkı incele — kodun etkilenmez
php artisan sk:publish --tag=form --destination=/tmp/sk-compare
diff -ru resources/js/components/Lvntr-Starter-Kit/FormBuilder /tmp/sk-compare/resources/js/components/Lvntr-Starter-Kit/FormBuilder
```

`--force` öncesi commit'le — eski versiyona Git üzerinden erişebilirsin.

> **Kurulu bir projede `php artisan sk:install` komutunu tekrar çalıştırmayın.** Bu rehberin önceki bir revizyonu komutu proje geneli kurtarma yolu olarak öneriyordu. Bu tavsiye yanlıştı ve geri çekildi — `sk:install` bir ilk-kurulum komutudur, onarım aracı değildir.
>
> - Yalnızca `lang/` korunabilir. Yayınlanan diğer her yol **`--force` olmadan da** üzerine yazılır; düzenlediğiniz bir controller, provider, route dosyası, Vue sayfası veya config paket sürümüyle değiştirilir.
> - Hash kaydı **sildiğiniz** bir dosyayı korur, **değiştirdiğiniz** dosyayı değil.
> - Bu kayıt `storage/starter-kit/hashes.json` yolunda durur ve git tarafından yok sayılır. Stateless bir deploy onu kaybederse `sk:install` uygulamayı **ilk kurulum** sayar: mevcut `.env` dosyanızın üzerine `.env.example` kopyalanır — veritabanı, cache, mail ve depolama kimlik bilgileri kaybolur — ardından boş kalan `APP_KEY` yeniden üretilebilir; bu da mevcut oturum çerezlerini ve `APP_KEY` ile şifrelenmiş her değeri okunamaz hale getirir.
>
> Kurulu bir projeyi onarmak için `sk:update` (hash farkındalıklı, düzenlemelerinizi korur) kullanın ya da tek bir alanı `sk:publish --tag=<alan> --force` ile sıfırlayın — öncesinde farkı `sk:publish --tag=<alan> --destination=/tmp/sk-compare` ile inceleyin. `sk:update`, `config/filesystems.php` ve `config/permission-resources.php` enjeksiyonlarını yeniden uygular; kalan kurulum-anı enjeksiyonlarının (`config/app.php`, `bootstrap/app.php`, provider kaydı, `media-library.php`, `services.php`) henüz otomatik bir onarım yolu yok — bunları `vendor/lvntr/laravel-starter-kit/stubs/` altındaki aynı göreli yola sahip stub'a karşı elle uygulayın.

## Hangi Durumda `sk:upgrade` Kullanılmalı

Laravel 12 -> 13 gibi starter-kit veya Laravel major geçişlerinde `sk:update` yerine `sk:upgrade` kullanın. Aynı ana sürüm hattındaki paket güncellemelerinde normal akış `sk:update`'tir.

Saat dilimi davranış değişikliği için mevcut kurulumlar aynı Laravel hattında kalsa bile `sk:upgrade` komutunu bir kez çalıştırmalıdır. İdempotent AST adımları, `config/app.php` içindeki eski `'display_timezone' => env('APP_TIMEZONE', ...)` girdisini `env('APP_DISPLAY_TIMEZONE', ...)` olarak yeniden yazar ve `config/database.php` içindeki mevcut `mysql` ile `mariadb` bağlantı dizilerine literal `'timezone' => '+00:00'` girdileri ekler. Mevcut bir `timezone` değeri değiştirilmez; eksik bağlantılar ile `sqlite`/`pgsql`/`sqlsrv` atlanır. `.env` dosyasına `APP_DISPLAY_TIMEZONE` ekleyin ve `APP_TIMEZONE=UTC` değerini koruyun.

Upgrade, veritabanı düzenlemesini uygulamadan önce varsayılan MySQL/MariaDB oturumunu ve `users` tablosunda veri bulunup bulunmadığını inceler. Veri varsa ve oturum offset'i UTC değilse iki `TIMESTAMP` yazma sınıfının zıt yönlerde hareket ettiğini bildirir, [tek seferlik dönüşüm rehberine](timezone.tr.md#mevcut-veriler-için-tek-seferlik-dönüşüm) yönlendirir ve `Pin the MySQL/MariaDB connection timezone to +00:00 now?` diye sorar. Onayı reddetmek veritabanı düzenlemesini atlar ve sonradan uygulanacak manuel adımı gösterir. Açık `--force` override'ı bulunmayan etkileşimsiz bir çalışma — `--no-interaction` veya TTY olmayan shell dahil — düzenlemeyi yine atlar; oturum/veri incelemesi başarısız olursa da işlem uygulanmaz. `--force` açık bir onay bypass'ıdır ve yalnız offset ile dönüşüm planı doğrulandıktan sonra kullanılmalıdır. Config rewrite açısından `sk:upgrade` komutunu yeniden çalıştırmak güvenlidir; ancak **komut mevcut satırları dönüştürmez ve hiçbir zaman dönüştürmeyecektir**. Yalnız config değişikliğini canlı bir veritabanına uygulamak, belgelenen dönüşüm eski satırları uzlaştırana kadar karışık bir veri seti oluşturur.

```bash
php artisan sk:upgrade
php artisan sk:upgrade --force
php artisan sk:upgrade --skip-build
```

## Hangi Dökümanlarla Birlikte Okunmalı

- ilk kurulum için [install.tr.md](./install.tr.md)
- komut detayları için [artisan-commands.tr.md](./artisan-commands.tr.md)
- daha derin mimari parçaları güncellemeden önce [project-documentation.tr.md](./project-documentation.tr.md)
