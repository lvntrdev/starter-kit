# UPGRADE — Lvntr Starter Kit

Bu dosya büyük sürümler arası geçiş rehberidir. Her sürüm kendi bölümünü taşır; son sürüm en üstte. Küçük hata düzeltmeleri yalnız `CHANGELOG.md`'de listelenir — bu dosyaya sadece **publish edilmiş** (yani `sk:install` ile user app'ine kopyalanan) dosyalara dokunan değişiklikler girer, çünkü bu tip değişiklikleri `composer update` tek başına taşımaz.

---

## Unreleased

### Local/public disk üzerindeki FileManager dosya URL'leri artık kalıcı public link değil

**Etkilenen:** FileManager (ya da avatar) media diski `local` ya da `public` olan kurulumlar — temporary/signed-URL desteği olmayan her disk. **Etkilenmeyen:** S3 ve temporary URL destekleyen herhangi bir disk (değişmedi).

`FileItemDTO::fromModel()`, disk `getTemporaryUrl()`'de hata fırlattığında (her local/public disk fırlatır) daha önce `Media::getUrl()`'e düşüyordu. Bu URL kalıcı, kimlik doğrulaması gerektirmiyor ve sonsuza dek çalışıyor: `FileManagerAuthorizer`'ı tamamen atlıyor, bir izin iptalinden sonra da dosyayı sunmaya devam ediyor, dosya çöp kutusuna taşındıktan sonra da sunmaya devam ediyor. Artık bunun yerine `files.download`'ın zaten kullandığı aynı `authorizeRead()` kontrolünden ve context guard'dan geçen yeni bir yetkili `files.preview` route'una düşülüyor; kimlik doğrulanmış bir oturum gerektiriyor (aynı origin'deki bir `<img>`/`<a>` için tarayıcı session cookie'sini otomatik gönderir).

**Kontrol edilecek:** local/public disk üzerindeki bir FileManager listelemesinden uygulamanızın sakladığı, e-postayla gönderdiği ya da başka şekilde dağıttığı herhangi bir URL (kaydedilmiş bir link, client tarafında cache'lenmiş bir değer, bir bildirimde gönderilen link) artık eski çıplak `/storage/...` yoluna işaret ediyor ve yalnızca o dosya o yolda fiziksel olarak durduğu sürece çalışmaya devam ediyor — geriye dönük olarak yetkilendirme KAZANMAYACAK, yeni preview URL'ine de OTOMATİK GÜNCELLENMEYECEK. Çalışmaya devam etmesi gerekiyorsa böyle bir link taze bir FileManager listelemesinden (ya da download linkinden) yeniden üretilmeli, erişim kontrollü olması gerekiyorsa `files.preview`/`files.download` linki olarak yeniden verilmeli. `FileItemDTO->url`'i her istekte taze okuyan bir tüketici (normal durum — shipped frontend'in hiçbir yeri onu kalıcılaştırmıyor) hiçbir değişiklik yapmak zorunda değil.

**Görsel yayımlamak hâlâ mümkün — `public_url` kullanın.** FileManager listeleme kaydı artık ikinci bir URL alanı taşıyor: disk `'visibility' => 'public'` ilan edilmişse `public_url` = `Media::getUrl()`, herkese açık okunmayan disklerde `null`. `url` oturuma bağlı önizleme bağlantısı olarak kalır ve dosya tarayıcısının her yerde kullandığı alandır. Yönetim oturumundan uzun yaşayan içeriğe dosya URL'si yazan her şey — kit'te bunun örneği zengin metin editörünün gömdüğü `<img src>` — `public_url` okumalı, yalnızca `null` ise `url`'ye düşmelidir. Kit'in kendi `EditorInput.vue` dosyası bunu zaten yapıyor. Kendi yayımlama yüzeyinizi `FileItemDTO->url` üzerine kurduysanız `public_url`'e geçirin.

**Range istekleri.** `files.preview` route'u `local` sürücülü disklerde `BinaryFileResponse` üzerinden servis eder; `Range`/`206` çalışır, satır içi oynatıcı ileri/geri sarabilir. Gerçek dosya sistemi yolu olmayan uzak sürücüler akış yanıtını korur ve eskisi gibi tüm gövdeyi `200` ile döndürür.

`Lvntr\StarterKit\Traits\HasMediaCollections::getMediaForForm()` (avatar ve diğer forma bağlı media için kullanılır) hâlâ doğrudan `Media::getUrl()` çağırıyor ve bu turda **kasıtlı olarak değiştirilmedi** — ayrı, daha dar bir yüzey (bir listeleme değil, bağlı bir form field'ı) ve aynı ham-URL desenini taşıyor; burada ele alınmadı.

### `stubs/package.json`'dan iki `@tiptap/*` paketi kaldırıldı

`@tiptap/extension-task-item` ve `@tiptap/extension-task-list`, stub'ın doğrudan bağımlılıklarından kaldırıldı — hiçbiri kitin kendi kodunda (`EditorInput.vue` ya da başka bir yerde) import edilmiyor. Kendi kodunuz bunlardan birini doğrudan import ediyorsa (özel bir rich-text extension'ı, editörün üzerine kurulmuş bir task-list özelliği) kendi uygulamanızın `package.json`'ına geri ekleyin; `sk:update`/`composer update`/`npm install` artık bunları sizin için, transitively bile, çekmiyor.

### `LogoutUserAction` artık mevcut credential'a bağlı refresh token'ı da iptal ediyor

`app/Http/Controllers/Admin/*`'ın logout yolu etkilenmiyor, ama stub `app/Domain/Auth/Actions/LogoutUserAction.php` etkileniyor: daha önce yalnızca `$user->token()?->revoke()` çağırıp duruyordu; bu, az önce iptal edilen access token'a bağlı canlı bir OAuth refresh token bırakıyordu — bir refresh token tasarım gereği access token'ından daha uzun yaşar, yani "logout" olduktan hemen sonra çağıran yeni bir access token basabiliyordu. Action artık önce refresh token'ı, sonra access token'ı iptal eden yeni bir `Lvntr\StarterKit\Domain\User\Concerns\RevokesOAuthCredentials` trait'ini kullanıyor.

**Bu published bir stub — `sk:update` düzenlediğiniz bir kopyanın üzerine sessizce yazmayacak.** `LogoutUserAction`'ı özelleştirdiyseniz (bir audit log çağrısı, bir `Fortify::logout()` hook'u, özel bir response eklediyseniz) düzeltme size otomatik ulaşmaz: diskteki hash artık yayınlanan hashle eşleşmiyor, bu yüzden üç-yönlü karşılaştırma dosyayı consumer düzenlemesi sayıp atlıyor (yukarıdaki "consumer tarafından değiştirilmiş published dosya" notuna bakın). Değişikliği elle taşıyın — `use Lvntr\StarterKit\Domain\User\Concerns\RevokesOAuthCredentials;`, sınıfa `use RevokesOAuthCredentials;` ekleyip `$user->token()?->revoke()` satırını `$this->revokeCurrentOAuthCredentials($user);` ile değiştirin. Burada `sk:update --force`'a **başvurmayın**: komut dosya argümanı almıyor, bu yüzden `--force` değişiklik korumasını yalnız bu dosya için değil *tüm* published dosyalar için kaldırır ve bütün özelleştirmelerinizi ezer. Stub'ı hiç özelleştirmemiş bir kurulum, düzeltmeyi bir sonraki `sk:update`'te otomatik alır.

`Lvntr\StarterKit\Domain\User\Actions\RevokeUserAccessAction`'daki aynı boşluk (bir operatör bir kullanıcının erişimini iptal ettiğinde, örn. Users ekranından) vendor kodunda düzeltildi ve yalnızca `composer update` gerektiriyor — taşınacak bir stub yok.

### `encryption:key` `--allow-acl-loss` bayrağı kazandı — artık miras alınan bir ACL uyuşmazlığını da kapsıyor, yalnızca kaybı değil

`.env`'inizde yalnızca grup sahipliğine güvenmek yerine bir POSIX ACL izni varsa (`setfacl -m u:www-data:r .env`, ya da macOS'ta `chmod +a` karşılığı), `encryption:key` artık bu ACL'in replacement dosyaya taşındığını doğrulayamadığı sürece rotasyonu tamamlamayı reddediyor — önceden sahip/grup/mode'u taşıyordu ama ACL'i sessizce düşürüyordu; `fileperms()` bunu ne öncesinde ne sonrasında görebiliyor. Dosyaya özel ACL'i olmayan kurulumların büyük çoğunluğu davranış değişikliği görmez.

Bayrak artık AYNA yönü de kapsıyor: geçici dosya `.env`'in kendi dizini içinde oluşturulduğundan, dizin seviyesinde bir ACL inheritance kuralı (`setfacl -d -m …`, ya da macOS'ta `chmod +a "… file_inherit"` karşılığı) `.env`'in hiç sahip olmadığı bir izni bu dosyaya koyabiliyor — mode kontrolü için aynı şekilde görünmez ve yeni anahtarın tek bir byte'ı bile yazılmadan önce orada. Rotasyon artık bu miras alınan girdi de normalize edilmediği sürece tamamlanmayı reddediyor. Yalnızca `.env`'in dizini bir inheritance kuralı taşıyorsa ilgili; çoğu kurulum davranış değişikliği görmez.

Ret tetiklenirse ve rotasyondan hemen sonra ACL'i elle uzlaştırmayı planlıyorsanız, işlemi durdurmak yerine bir uyarıya düşürmek için `--allow-acl-loss` geçin (konsola yazdırılır ve loga yazılır — uyarı yalnızca dosya yolu ve ACL metnini taşır, asla anahtar materyalini değil). Bunu varsayılan bir alışkanlık olarak geçmeyin: bu ret, rotasyondan sonra web sunucusunun okuyamadığı — ya da olmaması gereken bir prensibe erişim veren — bir `.env`'in kozmetik bir uyarı değil, bozuk bir deploy olduğu için var.

### `release.sh` artık `gh` ve etiketlenecek commit için yeşil bir uzak CI koşusu istiyor

Bu yalnızca bu paket için `./release.sh` çalıştıranı etkiler — herhangi bir tüketici uygulamasını etkilemez. Yayın akışı artık şu şekilde: commit → `git push origin main` → CI yeşillensin → `./release.sh`. Script, yerel kalite kapısını çalıştırmadan önce `gh run list --commit <HEAD sha>` sorguluyor ve `gh` yoksa, oturum açılmamışsa (`gh auth login`), commit hiç push edilmemişse ya da o commit için herhangi bir workflow'un en son koşusu `success`/`skipped` değilse duruyor. `--skip-checks`, öncekiyle aynı şekilde bunu da yerel kapının geri kalanıyla birlikte atlıyor.

## v13.6.16 → v13.7.0

### `sk:install` artık kendisinin kurmadığı bir uygulamada çalışmayı reddediyor

`sk:install` daha önce bir projenin zaten kurulu olduğunu anlamak için yalnızca kendi hash kaydına (`storage/starter-kit/hashes.json`) güveniyordu — bu kayıt git tarafından yok sayılır, yani stateless bir deploy ya da temizlenen bir `storage/` dizini canlı bir uygulamayı yepyeni gösterebiliyordu. Komut artık banner'dan önce fail-closed bir tespit adımı çalıştırıyor: kit'in şema tablolarını ve yalnızca bir kurulumun oluşturabileceği birkaç yolu arıyor. Bu kanıtlardan biri varsa ama kayıt yoksa komut hiçbir şey yazmadan durur ve tam olarak ne bulduğunu yazdırır.

Durma mesajı iki çıkış yolu adlandırır: kurulu bir uygulamayı değiştirmek için `sk:update`, kaydı diskteki dosyalardan yeniden inşa etmek için `php artisan sk:install --adopt` (önizlemek için `--dry-run` ekleyin) — bu hiçbir dosya kopyalamaz, hiçbir migration çalıştırmaz, `.env`'e hiç dokunmaz. `--force` gerçek bir uç durum için durmayı yine aşar; ama bunu "yukarıda listelenen yolların üzerine yaz" olarak okuyun ve zorlanmış bir koşunun ilk kurulum sayılMADIĞINI (varsayılan-domain eject yok, ilk-kurulum `.env` tohumlaması yok) unutmayın.

### `.env` artık asla üzerine yazılmıyor — mevcut bir `.env`'e sahip bir uygulamaya ilk kurulum artık merge ediyor

İlk kurulum, mevcut bir `.env`'in üzerine `.env.example`'ı olduğu gibi kopyalardı — bu, `sk:install`'in zaten bir `.env` taşıyan sıradan `composer create-project` şeklinde çalıştığı her durumda `DB_PASSWORD`, `APP_KEY` ve yapılandırılmış her şeyi yok ediyordu. `.env` artık her iki yolda da üzerine yazılmıyor. Dosya zaten varsa installer merge ediyor: `.env.example`'da olup `.env`'de eksik olan her anahtar ekleniyor, ilk-kuruluma-özel anahtarlar ise **yalnızca yoksa** tohumlanıyor. Mevcut hiçbir anahtarın değeri asla yeniden yazılmıyor. `.env`, yalnızca hiç yoksa `.env.example`'dan oluşturuluyor. Merge edilmiş dosya boş bırakırsa `APP_KEY` hâlâ üretiliyor, böylece uygulama boot edebiliyor.

### Consumer tarafından değiştirilmiş bir published dosya varsayılan olarak atlanıyor — opt-out `--force`

Hem `sk:install` hem `sk:update` artık bir published yolun üzerine yazılıp yazılmayacağına aynı üç-yönlü karşılaştırmayla (shipped stub hash'i vs. diskteki hash vs. son install/update'te kayda geçen hash) karar veriyor. Diskteki kopya kayda geçenle artık eşleşmiyorsa fark bir consumer düzenlemesi sayılıyor ve dosya sessizce üzerine yazılmak yerine **atlanıp raporlanıyor** — bu artık yalnızca `sk:update`'in değil, `sk:install`'in yeniden-publish yolunun da kapsamında. Yine de üzerine yazmak için `--force` verin; önce commit alın ki Git önceki sürümü erişilebilir tutsun.

Bu, aynı korumadaki geriye kalan tek boşluğu da kapatıyor: yeniden kurulumda, hash kaydında **hiç izi olmayan** bir dosya — çünkü yeni bir paket sürümü, bu uygulamada daha önce hiç göndermediği bir yola dosya göndermeye başladı — `--force` fark etmeksizin üzerine yazılıyordu. Artık bir consumer düzenlemesiyle aynı muameleyi görüyor: `--force` verilmedikçe üzerine yazılmak yerine korunup raporlanıyor. Bu koruma yalnızca yetkili bir kayıt varken geçerli; gerçek bir ilk kurulum, kıyaslayacak henüz hiçbir şey olmadığından izlensin izlenmesin her yolu yine yayınlıyor.

### İnaktif kullanıcılar oturum ortasında kesiliyor

Login yolu zaten aktif olmayan bir hesabı reddediyordu, ama zaten açık bir oturuma erişemiyordu — bir kullanıcıyı deaktive eden bir operatör o kullanıcının session cookie'sinin kendi kendine sona ermesini beklemek zorundaydı. Yeni bir `EnsureUserIsActive` middleware'i (otomatik olarak `web` ve `api` guard'larına bağlanır) artık her istekte kimliği doğrulanmış kullanıcının `status`'unu kontrol ediyor; operatörün deny-list'iyle eşleşiyorsa bir web oturumunu kapatıp login'e yönlendiriyor, bir API isteği için ise 403 döndürüyor.

Bu, her belirsiz durumda kasıtlı olarak **fail-open**: kimliği doğrulanmış kullanıcısı olmayan bir guard, çözülemeyen bir guard, `status` attribute'u olmayan bir user modeli, string olmayan bir `status` ve — en kritik — **deny-list'te olmayan** bir değer, hepsi olduğu gibi geçiyor. Middleware asla "aktif değil, o hâlde engellenmeli" diye çıkarım yapmıyor; yalnızca açıkça listelenmiş bir status'u engelliyor. Varsayılan deny-list `['inactive', 'banned']` — shipped `userStatus` tanımıyla eşleşiyor; kendi vokabülerini kullanan bir kurulum kendi değerlerini `starter-kit.security.active_status_denied` üzerinden ekliyor. `starter-kit.security.enforce_active_status = false` kill switch'tir — `bootstrap/app.php`'a hiç dokunmadan middleware'i tamamen devre dışı bırakmak için ayarlayın. Bu `security` bloğu var olmadan önce `config/starter-kit.php`'ı publish etmiş bir consumer da kapsam içinde: middleware, published config'de yeni anahtarlar eksikse shipped varsayılanlarla eşleşen class constant'larına düşüyor.

### Kurulum komutları artık zorunlu bir adım başarısız olduğunda sıfırdan farklı bir çıkış kodu döndürüyor

`sk:install`, `sk:update`, `sk:upgrade` ve yayınlanan `site:install` stub'ı `migrate`, `db:seed`, `vendor:publish`, `sk:seed-permissions`, `passport:keys`, `key:generate` gibi alt komutların sonucunu hiç okumadan çağırıyordu; bu yüzden başarısız bir migration yine `DONE` yazıyor, resume checkpoint'inde adımı tamamlanmış olarak kaydediyor ve komut `0` ile çıkıyordu — bir CI job'ı yarım kurulmuş bir uygulamanın üzerinden yeşil geçebiliyordu. Artık her alt komut sonucu denetleniyor; başarısız bir **zorunlu** adım (publish, migration, seeder, izin tohumlama, Passport anahtarları, şifreleme anahtarları) artık koşuyu sıfırdan farklı bir çıkış koduyla durduruyor, checkpoint'i beklemede bırakıyor (`sk:install --resume` kaldığı yerden devam ediyor) ve stub-hash registry yazımını atlıyor.

Sessizce başarısız olan bir installer adımına rağmen şu an geçen bir CI hattı bu yükseltmeden sonra başarısız olmaya başlayacaktır — bu, etrafından dolaşılacak bir regresyon değil, amaçlanan sinyaldir. Frontend ve tooling adımları (`npm install`, Wayfinder üretimi, `npm run build`, `composer dump-autoload`, cache temizlikleri) bilerek ölümcül değil: yalnızca uyarıyor, elle çalıştırılacak komutu yazdırıyor ve kapanış özetinde tekrar listeleniyor; böylece Node ya da Composer'ı olmayan bir makine bugünkü gibi kurulmaya devam ediyor. `site:install` değişikliği `stubs/` üzerinden geliyor, dolayısıyla yalnızca yeni kurulumlara ve `sk:update` ile tazelenen uygulamalara ulaşıyor — mevcut, dokunulmamış bir consumer `site:install` kopyası değişmiyor.

Kurulum sırasında ulaşılamayan bir veritabanı eskiden yine `Lvntr Starter Kit installed successfully!` yazdırıyor ve `0` ile çıkıyordu — veritabanı bloğu (migration, seeder, izin tohumlama) yalnızca ekrandaki bir uyarıyla atlanıyordu. Bu koşu artık kurulumu **eksik** olarak bitiriyor: hiçbir stub-hash registry yazılmıyor, tamamlanan dosya-sistemi adımlarına ait resume checkpoint'i korunuyor ve komut sıfırdan farklı bir çıkış koduyla çıkıyor. Veritabanı bağlantısını düzeltip `php artisan sk:install --resume` çalıştırın; böylece baştan başlamak yerine tam kaldığı yerden devam eder.

### `migrate:fresh` artık yazılı bir onay istiyor

`sk:install`, tabloları zaten dolu mevcut bir veritabanı bulduğunda "Tüm tabloları sil ve migration'ları sıfırdan çalıştır" seçeneğini de içeren bir `select()` menüsü sunuyordu; bu seçenek sıradan bir evet/hayır `select()` yanıtıyla onaylanıyordu — geri dönüşü olmayan bir `migrate:fresh`'ten yalnızca bir yanlış tuşa basma kadar uzaktaydı. Bu seçenek artık **yazılı** bir onay arkasına alındı: operatör silme işlemi çalışmadan önce bir `text()` prompt'unda veritabanı adını (ya da harfiyen `fresh` kelimesini) yazmak zorunda; bunun dışındaki her yanıt — boş bir cevap ya da refleks bir `y` dahil — hiçbir şey silinmeden ek türden `migrate` yoluna düşüyor. Yıkıcı seçenek ayrıca — prompt sebebini açıklıyor — `APP_ENV` production benzeri göründüğünde, `APP_DEBUG` kapalıyken, oturum hiç prompt gösteremediğinde (`--no-interaction`, CI, TTY yok) ya da mevcut bir tablo zaten satır içeriyorsa (okunamayan bir tablo veri içeriyor sayılır) baştan sunulmuyor. Yazılı onayın kendisi etrafında bir kaçış yolu yok; tek atlama yolu boş bir veritabanına karşı çalışmak ya da `migrate`'i kullanmak.

### `sk:install` artık kurtarma yolu olarak belgelenmiyor

`docs/install.tr.md` ve `docs/update.tr.md`, mevcut bir projede `php artisan sk:install` komutunu yeniden çalıştırmayı idempotent bir proje-geneli kurtarma adımı olarak tarif ediyordu. Öyle değil ve bu tavsiye geri çekildi — deploy stratejinizde `storage/starter-kit/` dizinini kalıcı operasyon durumu olarak ele alın, tıpkı `storage/app/` gibi sürümler arasında yaşamalıdır.

Bu tavsiyenin geri çekilmesine yol açan risklerden ikisi artık açık değil, yukarıda ele alınıyor: eksik bir registry artık `sk:install`'ın kurulu bir uygulamayı sessizce ilk kurulum sayması sonucunu doğurmuyor (yukarıdaki "`sk:install` artık kendisinin kurmadığı bir uygulamada çalışmayı reddediyor" bölümüne bakın), mevcut bir `.env`'e sahip bir uygulamaya yapılan ilk kurulum da artık onun üzerine yazmıyor (yukarıdaki "`.env` artık asla üzerine yazılmıyor" bölümüne bakın). `sk:install`'i zaten kurulu bir uygulamaya dokunmak için yanlış araç yapan şey, yukarıdaki "Consumer tarafından değiştirilmiş bir published dosya varsayılan olarak atlanıyor" bölümünde anlatılan consumer-edit davranışıdır: `--force` verilmeden düzenlenmiş bir dosya tazelenmek yerine atlanıp raporlanıyor, `--force` ile ise doğrudan üzerine yazılıyor — ikisi de `sk:update` ya da kapsamı daraltılmış `sk:publish --tag=<alan>`'ın verdiği seçici, düzenlemeyi koruyan tazelemeyi vermiyor. Kurulu bir uygulamayı değiştirmek için bunları kullanın.

### `DATA_ENCRYPTION_CIPHER` ile `app.cipher` eşleşmek zorunda — zorunlu kılındı

Okuma zincirinin tamamı (`DATA_ENCRYPTION_KEY`, `DATA_ENCRYPTION_PREVIOUS_KEYS[n]`, `APP_PREVIOUS_KEYS[n]`, `APP_KEY`) **tek** bir cipher ile kullanılır; dolayısıyla `app.cipher` değerinden farklı bir `DATA_ENCRYPTION_CIPHER`, anahtarı listede olsa bile diğer cipher ile yazılmış satırları okunamaz bırakır. `DataEncrypterFactory::cipher()` artık geç gelen kapalı bir `DecryptException` yerine her iki değeri de adlandıran bir `RuntimeException` fırlatıyor. Değişkeni kaldırın ya da `app.cipher` ile aynı değere ayarlayın.

Şifreli ayar veya 2FA verisi barındıran bir veritabanında `app.cipher` değerini değiştirmek **tek yönlü bir sınırdır**: önceki-anahtar zinciri anahtar başına cipher taşımadığı için eski payload'lar okunamaz hale gelir ve ne `encryption:health` ne de `encryption:rekey` onları kurtarabilir. Değiştirmek zorundaysanız önce **eski cipher altında** rekey yapın, `encryption:health` ile doğrulayın, yedek alın ve ancak sonra geçin — bunu bir config düzenlemesi değil, migration olarak ele alın.

### Aktivite kaydı morph genişletme migration'ı — yalnız ileri düzeltme

`2026_06_20_000000_widen_activity_log_morphs_to_string`, UUID kullanıcılar ile bigint Role/Permission id'lerinin aynı tabloyu paylaşabilmesi için `activity_log.subject_id` / `causer_id` kolonlarını `char(36)` genişliğine çeker. `down()` metodu her iki kolonu `uuid` tipine geri döndürür. MariaDB 10.7+ üzerinde bu **native UUID tipidir**; sayısal subject/causer id barındıran bir tabloyu geri almak hata verir ya da veriyi budar. MySQL'de iki tip çakıştığı için risk testte kolayca gözden kaçar.

Dolu bir `activity_log` üzerinde bu migration'ı geri almayın. Yeni bir migration ile ileri düzeltin ya da yükseltme öncesi alınan yedekten dönün.

### Aktivite kaydı kimlik bilgisi redaksiyonu — migration öncesi yedek alın

Yeni aktivite satırları artık hassas alanları kaydetmiyor; ancak mevcut `activity_log` satırları hâlâ parola hash'leri, token'lar veya secret'lar içerebilir. Yeni data-only migration bu anahtarları hem `attribute_changes` hem `properties` JSON kolonundan recursive olarak kaldırır.

Migration **paketin içinde** (`database/migrations/`, kitin diğer şema dosyaları gibi otomatik yüklenir) gelir; dolayısıyla yalnızca `composer update` ile taşınır — almak için `sk:install` / `sk:update` gerekmez. İlk `php artisan migrate` çalıştırmasında devreye girer; aşağıdaki yedek bu yüzden isteğe bağlı değildir.

Bu redaksiyon **GERİ DÖNDÜRÜLEMEZ**. `php artisan migrate` çalıştırmadan önce veritabanı yedeği almak **ZORUNLUDUR**. Silinen kimlik bilgisi materyali yeniden oluşturulamayacağı için migration'ın `down()` metodu bilinçli olarak no-op'tur.

Yedeği aldıktan sonra normal migration'ı çalıştırın:

```bash
php artisan migrate
```

Migration, hassas anahtar ön filtresini kullanmak yerine tüm satırları tarar: MySQL'de JSON kolonu büyük/küçük harfe duyarlı karşılaştırıldığı için farklı yazılmış bir anahtar (`Password`) aksi hâlde atlanırdı. Çok büyük bir `activity_log` tablosunda bu adım bir süre çalışır; tablo kilidi almaz ve her 500 satırlık sayfa için tek bir kısa transaction commit eder.

`php artisan sk:doctor` çıktısına `activity-log-secrets` kontrolü eklendi; böylece migration'ı hiç çalıştırmamış bir kurulum sessiz kalmak yerine FAIL raporlar. Bu kontrol ikinci bir tam geçiş değil, sınırlı ve salt-okunur bir sondadır: birincil anahtara göre ilk 500 satırı okur — PostgreSQL dahil her sürücüde aynı sabit maliyet — ve kararı PHP'de verir; böylece farklı yazılmış bir anahtar collation'dan bağımsız olarak yakalanır. Bu pencerede durduğu için, büyük tablolarda bulgu bir alt sınır ("en az N") olarak raporlanır ve temiz sonuç taradığı pencereyi adlandırır. Tam sayıma ihtiyacınız olduğunda `php artisan sk:redact-activity-secrets --dry-run --all` kullanın. `--all` bayrağı önemlidir: onsuz komut MySQL, MariaDB ve SQLite'ta SQL tarafında bir anahtar-adı ön filtresine düşer ve farklı yazılmış bir anahtar bu filtreden kaçabilir.

Alttaki komut idempotent'tir; eski bir yedeği geri yükledikten sonra da ayrıca çalıştırılabilir:

```bash
php artisan sk:redact-activity-secrets --dry-run
php artisan sk:redact-activity-secrets
php artisan sk:redact-activity-secrets --chunk=500
php artisan sk:redact-activity-secrets --all
```

- `--dry-run` yazma yapmadan değişecek satırları raporlar.
- `--chunk=` her turda işlenen satır sayısını belirler (varsayılan 500, en fazla 5000).
- `--all`, hassas anahtar ön filtresini kullanmak yerine tüm satırları tarar.

Komut bir JSON payload'ının decode edilemediğini bildirirse o payload değiştirilmeden bırakılır ve hâlâ kimlik bilgisi içerebilir. Yükseltmeyi tamamlanmış saymadan önce bildirilen her satırı elle inceleyip redact edin.

### FileManager context ability'leri — BREAKING

Consumer tarafından kaydedilen FileManager context closure'ları artık yalnızca `read`, `create`, `update` veya `delete` değerlerinden birini alır. Kit artık **hiçbir zaman `write` göndermez**.

Dokümante edilmiş okuma-mutasyon ayrımı biçimini kullanan closure güvenli kalır:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
    $ability === 'read' ? $readCheck : $writeCheck,
```

Ancak ters legacy biçim tehlikelidir:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
    $ability === 'write' ? $writeCheck : $readCheck,
```

`write` artık gönderilmediği için her mutasyon bu closure'ın **okuma dalına** düşer. `$readCheck`, `$writeCheck`'ten daha genişse create, update ve delete istekleri sessizce **fazla yetkilendirilebilir**.

Consumer tarafından kaydedilen her closure'ı dört ability adını da açıkça eşleyecek biçimde yeniden yazın:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool => match ($ability) {
    'read' => $readCheck,
    'create' => $createCheck,
    'update' => $updateCheck,
    'delete' => $deleteCheck,
    default => false,
},
```

Built-in `global` context artık bu ability'leri birebir `files.read`, `files.create`, `files.update` ve `files.delete` ile eşler. Bu nedenle yalnız `files.create` sahibi bir rol artık silme veya çöpü boşaltma erişimine, yalnız `files.update` sahibi bir rol ise okuma erişimine sahip değildir. Her role ihtiyaç duyduğu belirli `files.*` yetkilerini verin, ardından seed edilmiş yetkileri yeniden oluşturun:

```bash
php artisan sk:seed-permissions
```

### Çözülemeyen route'larda fail-closed, mevcut kurulum için opt-in

**Bu sürümde hiçbir şey kırılmıyor.** `CheckResourcePermission` tarafından izni çözülemeyen bir route bugün **hâlâ geçer** — tıpkı önceki gibi; middleware artık ayrıca route'u adlandıran, throttle edilmiş bir uyarı logu basar; böylece boşluk sessiz kalmak yerine görünür olur. Şu anda başarılı olan hiçbir istek bu sürüm yüzünden başarısız olmaya başlamaz.

Kitin kendi route'ları **paketin içinde** (`src/`) düzeltildi: kitin gönderdiği her route artık kendi başına bir izne çözülüyor. Mevcut bir kurulum bu düzeltmeyi yalnızca `composer update` ile alır — **route dosyası düzenlemesi yok, `sk:update` reconciliation'ı yok**. Gerekçe, route'ların pakette yaşaması değil: route'lar `stubs/routes/web/*-route.php` içinde kayıtlı ve `sk:install` onları app'inize kopyaladı. `src/` içinde yaşayan şey *sözleşme*: `CheckResourcePermission` içindeki, o dosyaların zaten kullandığı adlarla anahtarlanmış bir route-adı → izin haritası. Düzeltme bu yüzden düzenlemiş olabileceğiniz bir dosyaya dokunmadan geliyor.

Madalyonun diğer yüzü de bilinmeli: **kitin route'larından birini kendi kopyanızda yeniden adlandırdıysanız harita artık onu tutmuyor**; o route uyarıyla geçmeye geri döner ve bayrak çevrildikten sonra reddedilir. `sk:doctor --only=unresolved-routes` tam olarak bunları listeler.

Bir durum middleware katmanında bilinçli olarak kapatılmadı: `roles.bulk` ve `users.bulk`. Bu uçların gerektirdiği ability, route'un değil istek gövdesinde adı geçen aksiyonun bir özelliği; `BulkActionDispatcher` zaten her item'ı handler'ın kendi ability'siyle yetkilendiriyor (`BulkDeleteUserAction` `users.delete` istiyor). Route seviyesinde tek bir eşleme yalnızca fazla-reddedebilirdi — `.delete`, `.update` ve `.read`'in her biri farklı bir meşru rolü kırar, çünkü bu ability'ler `permission-resources.php` içinde birbirinden bağımsız. Bu yüzden paketin muaf listesine yazıldılar; bu aynı zamanda onları çözülemeyen ekseninden çıkarıyor, böylece bayrak çevrildiğinde bulk aksiyonları kırılamaz. Item bazlı yetkilendirme değişmedi ve asıl kapı olmayı sürdürüyor.

Varsayılan değişmeden önce izlenecek **sıralı düzeltme yolu**:

1. `php artisan sk:doctor --only=unresolved-routes` çalıştırarak kendi app'inizde hâlâ uyarıyla geçen her route'u listeleyin.
2. Listelenen her route'u şu yollardan biriyle düzeltin:
   - Action segmenti middleware'in ability haritasında olan bir `<resource>.<action>` route adı verin; böylece izin otomatik çözülür.
   - Açık bir izin argümanıyla gate edin, örn. `check.permission:reports.read`.
   - Route bilinçli olarak izinsiz kalacaksa (public bir webhook, health check, …) `starter-kit.permissions.unrestricted_routes` altında tanımlayın (dar `Str::is` desenleri — geniş bir desen sonradan eklenen route'ları da sessizce muaf tuttuğu için, ağaç yerine tek tek endpoint listelemeyi tercih edin).
3. 1-2. adımlar bittiğinde staging ortamında `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` (ya da `starter-kit.permissions.allow_unresolved` = `false`) ayarlayıp güvendiğiniz hiçbir şeyin reddedilmediğini doğrulayın. Staging temiz çıktığında aynı değeri production'da da verin. Opt-in'in tamamı bu satır — başka hiçbir şeyin değişmesi gerekmiyor ve bunu sizin yerinize kimse yapmayacak.

**Kitin config'ini publish ettiyseniz** (`php artisan sk:publish --tag=config`), `config/starter-kit.php` kopyanız iki yeni anahtardan da önce oluşmuştur ve `mergeConfigFrom` yalnızca en üst seviyede birleştirir — paketin `permissions` dizisi sizinkinin içindeki boşlukları doldurmaz. Bir şey kırılmaz: `allow_unresolved` kod tarafında paket varsayılanına düşer, olmayan `unrestricted_routes` ise boş liste olarak okunur. Ancak 2. adımdaki üçüncü seçenek, publish edilmiş `permissions` dizinize `'unrestricted_routes' => [...]` anahtarını kendiniz eklemeden hiçbir işe yaramaz. İki anahtarı da almak için kopyanızı `vendor/lvntr/laravel-starter-kit/config/starter-kit.php` ile karşılaştırın.

**Hiçbir sürüm bunu sizin yerinize çevirmeyecek.** `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES` (config `starter-kit.permissions.allow_unresolved`), değeri kendisi vermeyen bir uygulama için varsayılan olarak `true`'dur ve bu varsayılan 13.x'in hiçbir yerinde değişmiyor. Uygulamanızın kendiliğinden reddetmeye başlayacağı planlı bir sürüm yok; bunu açan tek şey 3. adım ve zamanlamasına siz karar veriyorsunuz.

Öylece değiştirilmemesinin gerekçesi, değişimin erişim alanı. Config'i hiç publish etmemiş bir kurulum da, publish edilmiş kopyası bu anahtardan önce oluşmuş bir kurulum da paketin kendi sınıf sabitine düşer; o sabiti çevirmek, hiç kimse bir dosyaya dokunmadan, yalnızca `composer update` çalıştıran her uygulamada yetkilendirme davranışını değiştirirdi. Bu erişime sahip bir varsayılan, bir sürüm hattının içinde güvenle değiştirilebilecek bir varsayılan değildir; ileride tekrar ele alınırsa yeri kendi upgrade notuyla birlikte bir major sürümdür.

**Yepyeni bir proje farklı ve zaten sıkı.** `sk:install`, oluşturduğu `.env` dosyasına `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` yazıyor: sıfırdan kurulan bir uygulamada geçmişten devralınacak route yok, dolayısıyla fail-closed başlıyor ve ilk izinsiz route'u production'da değil geliştirme sırasında yakalanıyor. Bu yalnızca ilk kurulum için geçerli — mevcut bir uygulamada `sk:install`'ı yeniden çalıştırmak bu anahtarı eklemez; `sk:update` ve `sk:upgrade` da eklemez.

Değeri hangi yöne verirseniz verin, env değişkeni production'da geçerli kaçış kapısı olarak kalır — düzeltmeyi bitirmek için daha fazla zamana ihtiyacınız varsa `true`'ya geri alabilirsiniz; ancak çözülememiş her route, tanımı gereği, o hâlde kaldığı sürece izinsiz (ungated) demektir.

### Sayfalar-arası toplu seçim artık desteklenmeyen filtrelerde fail-closed davranıyor

Bir sayfa, kit'in Users veya Roles tablosuna kendi filtresini ekliyorsa — datatable'ın tanımlamadığı özel bir `filter[...]` anahtarı — artık "tümünü seç"e tıklandığında bu filtre aktifken toplu işlem sessizce o filtreyi yok sayan bir küme üzerinde çalışmak yerine **422** (`sk-bulk.unknown_filters`) döner. Bu sürümden önce desteklenmeyen aktif bir filtre snapshot'tan düşürülüyordu ve çözülen küme tablonun gösterdiğinden **daha geniş** oluyordu — filtrenin gizlemesi gereken satırları da siliyor ya da onlar üzerinde işlem yapıyordu.

Boş bir değer de aktiftir. `filter[status]=` (boş ya da yalnızca boşluktan oluşan bir string) tablo tarafında `WHERE status = ''` olarak uygulanır — boş bir küme — bu yüzden toplu seçim tarafı artık onu "filtre yok" saymak yerine olduğu gibi geçiriyor: desteklenen bir anahtarda tablonun gösterdiği aynı (boş) kümeyi çözer, desteklenmeyen bir anahtarda ise aynı 422 ile reddedilir. Yalnızca `null` değer ya da boş dizi yok sayılır; bu, Spatie'nin `AllowedFilter`'ının atladığı şekillerle birebir aynıdır. Gönderilen `SkDatatable` URL'ye asla boş bir filtre yazmaz, dolayısıyla stok Users/Roles sayfaları etkilenmez; kendi `filter_snapshot`'ını üreten bir sayfa boş anahtarları göndermek yerine düşürmelidir.

**422'yi, desteklenmeyen filtreyi backend'e ulaşmadan snapshot'tan çıkararak "düzeltmeyin"** — bu, kümeyi eski, güvensiz davranışa geri genişletir. Bunun yerine query sınıfının gerçekten uyguladığı allow-list'i genişletin (`UserBulkSelectionQuery::ALLOWED_FILTERS` / Roles karşılığı) ki yeni filtre tablonun kullandığı aynı semantikle uygulansın, ya da o filtre aktifken sayfalar-arası seçimi devre dışı bırakıp satır bazlı seçime geri dönün.

Bu düzeltme vendor query sınıflarında yaşıyor (`Lvntr\StarterKit\Domain\User\Queries\UserBulkSelectionQuery`, `Lvntr\StarterKit\Domain\Role\Queries\RoleBulkSelectionQuery`). **Bu sorgulardan birinin vendor namespace'i dışına çıkarılmış bir kopyası — `make:sk-domain` ile ya da elle — `composer update` ile bu düzeltmeyi almaz**; kopyanızı vendor kaynağıyla yeniden diff'leyin. Aynı şekilde, `php artisan sk:publish --tag=composables` ile publish edilmiş bir `useDatatableSelection.ts` kopyası, siz yeniden publish edene ya da aşağıda anlatılan değişikliği elle taşıyana kadar eski gönderdiği id şeklini göndermeye devam eder.

### `BulkActionRequest` sayfalar-arası modda artık `ids` istemiyor

`app/Http/Requests/Admin/BulkActionRequest.php`, `select_all_filtered` `true` olsa bile `ids` (`min:1`) istiyordu; bu, belgelenmiş payload'la (`ids` o durumda boş gelir) çelişiyordu — `useDatatableSelection().executeBulkAction()`'ı mevcut sayfada hiçbir şey seçili değilken doğrudan çağıran bir host 422 (`sk-bulk.ids_required`) alıyordu. Kural artık `Rule::requiredIf(! select_all_filtered)`; gönderilen id'ler yine doğrulanır (`array`, `max:500`, opak string) ve sayfalar-arası küme seçim sorgusunun `MAX_ITEMS` sınırıyla bağlı kalır. Bu publish edilen bir stub'dır: değiştirilmemiş bir kopya `php artisan sk:update` ile yenilenir; düzenlediğiniz bir kopya siz değişikliği taşıyana kadar eski kuralı korur.

### Toplu seçim id'leri opak string olarak gönderiliyor, sayısal dönüşüm yok

`useDatatableSelection()`'ın `executeBulkAction()`'ı artık seçili satır id'lerini göndermeden önce dönüştürmüyor. Backend `ids.*` doğrulaması zaten `string|min:1|max:64` kabul ediyordu — yani UUID/ULID birincil anahtarlar zaten geçerliydi — ama sayısal görünümlü bir id daha önce bir dönüşüm adımından geçebiliyordu. Kendi toplu işlem endpoint'iniz `ids`'i katı bir integer cast ile ayrıştırıyorsa, `idKey` kolonunuzun kullandığı tam string tipini hâlâ kabul ettiğinden emin olun.

### `DatatableQueryBuilder::columns()` payload şekillendirmesi fail-closed

`columns()` tanımlayan ve **hiçbir tanımlı sütunla eşleşmeyen** bir `?columns=` istek parametresi alan bir backend artık her satırı yalnızca `alwaysInclude()` anahtarlarına indirger — artık tam satıra geri dönmez. `columns` parametresinin hiç bulunmaması bu davranıştan etkilenmez ve tam satır dönmeye devam eder. Frontend sütun anahtarı ile karşılık gelen backend `columns()` anahtarı bir tarafta yeniden adlandırılıp diğerinde kalmışsa, etkilenen hücreler artık tam payload'ın uyumsuzluğu maskelemesi yerine boş render edilir; güncelleme sonrası eksik hücre verisi görürseniz her iki tarafı da denetleyin.

### `definitions.lang` daraltılıyor — migration reddedebilir

`create_definitions_table`, üç varsayılan `string()` (255 karakter, utf8mb4) sütun üzerinde `unique(['key', 'value', 'lang'])` tanımlıyordu — MySQL/MariaDB'nin 3072 byte'lık InnoDB anahtar sınırının 3060'ında, herhangi bir sütunun tek bir karakter genişlemesi bu sınırı kırardı. Yeni bir migration **yalnızca `lang`'i** 35 karaktere daraltıyor — 35, kitin herhangi bir yerde kabul ettiği en geniş locale değeri olan `content_languages.code`'dan alındı; böylece kitin kendi ekranlarından saklayabileceğiniz her etiket sığmaya devam ediyor ve aşağıdaki ret bu değerler için erişilemez kalıyor. `key` ve `value` yayımlanmış 255 genişliğini koruyor: tek başına `lang` indeksi 2180 byte'a indiriyor, yani sınırın ~892 byte altına; onları da daraltmak yalnızca mevcut şemanın kabul ettiği veriyi bloke ederdi.

**Şemaya dokunmadan önce migration, mevcut her satırı ölçer** (`lang` karakter uzunluğu, soft-delete edilenler dahil — bunlar hâlâ unique indeksi işgal eder) ve herhangi bir satır yeni sınırda karakter kaybedecekse — şemayı değiştirmeden — reddeder. Verinizde reddederse:

1. Hatayı okuyun — sütunu, sınırı aşan satır sayısını ve bulunan en uzun değeri adlandırır.
2. Sorunlu `definitions` satırlarını kısaltın veya silin (soft-delete edilenler dahil — `deleted_at`, bir satırı unique indeksten muaf tutmaz).
3. `php artisan migrate`'i yeniden çalıştırın.

Migration doğrudan geri alınabilir (`down()` `lang`'i 255'e geri genişletir — bir genişletme asla kırpmaz, dolayısıyla ölçüme gerek duymaz). Her iki yön de unique indeksin var olduğunu doğrulayarak biter; böylece indeksi zaten eksik hâlde bu migration'a ulaşan bir tablo (yarıda kalmış önceki bir koşu) garantisi olmadan "migrate edildi" diye kaydedilmek yerine indeksi yeniden kurulur. Kit'in kendi ~34 tohumlanmış satırının çok ötesine büyümüş bir tabloda `ALTER TABLE` + indeks yeniden kurma işlemi süresince bir metadata kilidi tutar; büyük bir tablodaki başka herhangi bir ALTER kadar dikkatle planlayın.

### `media` tablosu migration'ı artık bir rollback yoluna sahip — yıkmak yerine reddeden bir yol

`create_media_table`'ın bir `down()`'ı yoktu. Laravel'in migrator'ı bu çağrıyı `method_exists` ile koruduğu için `php artisan migrate:rollback` hata vermiyordu — tabloyu sessizce atlıyor, ama migration'ın kayıt defteri satırını yine de siliyordu; geriye uygulamanın artık kaydını tutmadığı bir `media` tablosu ve o tabloda patlayan bir yeniden `migrate` kalıyordu. Artık bir `down()` tanımlıyor: tablo boşsa düşürülüyor, içinde satır varken denenen bir rollback ise tabloyu adıyla anan bir hatayla duruyor. Aynı zincirdeki iki sonraki migration (`add_folder_id_to_media_table`, `add_soft_deletes_to_media_table`) da birebir aynı reddi taşıyor; çünkü bir batch en yeniden en eskiye doğru geri alınır: bu olmadan, create migration'ının koruması hiç devreye girmeden dolu bir tablodan `folder_id` ve `deleted_at` düşürülmüş olurdu.

**Bu, mevcut tüketiciler için bir davranış değişikliği**: bu migration'ın ait olduğu batch'i kapsayan bir `migrate:rollback` daha önce `media`'yı sessizce atlıyordu; media satırı olan bir kurulumda artık **hata veriyor**. Bu hata özelliğin kendisi. Dolu bir `media`'yı düşürmek **satırları** kaldırırdı, **dosyaları** değil: her satır yapılandırılmış bir disk üzerindeki bir blob'a işaret eder ve Spatie bu blob'u yalnızca modelin deleting event'i üzerinden siler — bir şema rollback'i Eloquent'i tamamen atlar, yani storage dizinleri bozulmadan kalırken onların tek indeksi yok edilir ve uygulamanın artık numaralandıramayacağı öksüz dosyalar geriye kalırdı.

Migration'ı bilerek geri almak için önce media'yı **uygulama üzerinden** silin — böylece blob'lar satırlarla birlikte gider — sonra rollback'i tekrar çalıştırın. Korumayı aşmak için tabloyu ham SQL ile boşaltmayın; bu, korumanın engellemek için var olduğu öksüzleşmeyi bire bir üretir.

### Dosya yüklemeleri artık bir client-uzantı allow-list'i uyguluyor

FileManager ve avatar yüklemeleri artık yalnızca sniff edilen content-type eşleşmesiyle geçmiyor — client dosya adının uzantısı da kontrol ediliyor ve `media-library.disallowed_extensions` artık `html`, `htm`, `xhtml`, `xht`, `svg`, `svgz`, `xml`, `xsl`, `xslt`, `js`, `mjs` ve `hta`'yı adın yalnızca son değil her nokta segmentinde engelliyor (bu yüzden `name.html.pdf`, `.pdf` son uzantı olsa bile reddediliyor). Nedeni için `CHANGELOG.tr.md`'ye bakın. Burada hiçbir şey veritabanına veya `.env`'e dokunmuyor; iki şey bir operatörün dikkatini gerektiriyor.

**Avatar request stub'ı consumer-sahipli.** `stubs/app/Http/Requests/UploadAvatarRequest.php`, `sk:install` tarafından uygulamanıza kopyalandı ve kit zaten size teslim ettiği bir dosyaya geri erişemez. Mevcut bir kurulum, siz ya tazelenmiş stub'ı almak için `php artisan sk:update` çalıştırana ya da kendi kopyanızın `rules()` dizisine — mevcut `'mimes:jpg,jpeg,png,webp'` satırının hemen ardına — `'extensions:jpg,jpeg,png,webp'`'i elle ekleyene kadar eski kurallarını (`image`, `mimes:jpg,jpeg,png,webp`, uzantı kontrolü yok) koruyor.

**`media-library.disallowed_extensions` sertleştirmesi koşulsuz uygulanıyor — kapatacak bir bayrak yok.** Kendi kopyanızı publish ettiğiniz an geri çekilen kitin `media-library.php`/`activitylog.php` override'larının aksine, bu merge her boot'ta koşulsuz çalışıyor. Uygulamanız yeni engellenen uzantılardan birini gerçekten kabul etmesi gerekiyorsa, kabul ettiğiniz MIME/uzantı çiftini bildirmek için alt sınıflanmış bir request'te `mimeExtensionMap()`'i override edin ve o uzantıyı kendi service provider'ınızda (kitinkinden sonra register edilmiş) `media-library.disallowed_extensions`'tan kendiniz çıkarın — bunu yapmanın bu sertleştirmenin kapattığı aktif-içerik riskini yeniden açtığını bilerek.

**Rename uzantıyı korur; boyut tavanı hizalandı.** `PATCH files/{media}` artık uzantısı saklanan dosyanınkinden farklı olan ya da yasaklı bir segment içeren (`report.php.pdf`) yeni adı 422 ile reddediyor. Kitin `mimeExtensionMap()`'i dışındaki kabul edilen MIME tipleri (PPTX, RAR, Markdown, …) uzantılarını Symfony'nin MIME veritabanından çözüyor; yukarıdaki alt sınıf override'ı yalnız ikisinin de tanımadığı bir MIME için gerekiyor. Media library'nin kendi `media-library.max_file_size` tavanı (varsayılan 10 MB) FileManager'ın `max_size_mb` ayarından bağımsızdır: bu tavanı aşan bir yükleme artık 500 yerine 422 ("The uploaded file is too large.") ile reddediliyor ve yenilenen `app/Providers/SettingsServiceProvider.php` stub'ı `max_file_size`'ı `max_size_mb`'den set ediyor — `php artisan sk:update` ile çekin ya da kendi kopyanızdaki mevcut `file-manager.settings.max_size_mb` yazımının yanına `config(['media-library.max_file_size' => $maxSizeMb * 1024 * 1024])` ekleyin.

Segment bazlı engelleme (yukarıdaki `name.html.pdf` durumu) `spatie/laravel-medialibrary` **11.23.0** (2026-05-28) ile geldi; kitin `composer.json`'ı artık `^11.23` istiyor, dolayısıyla bu sürümü getiren `composer update` listeyi uygulayan bir build'i de çeker. Eski bir build'in hâlâ kurulu olduğu aralığı kitin kendi request seviyesindeki segment kontrolü kapatır. Şüphede kalırsanız `composer show spatie/laravel-medialibrary` ile kontrol edin.

### Ayarlar cache anahtarı değişti — işlem gerekmiyor

`SettingService` artık `settings` altında çözülmüş değerleri cache'lemek yerine `settings:v2` altında ham (ciphertext) satırları cache'liyor; nedeni için `CHANGELOG.tr.md`'ye bakın. Herhangi bir deploy adımı gerekmiyor: eski `settings` anahtarı, yeni snapshot ilk kurulduğunda otomatik olarak düşürülüyor. Beklemek yerine kalıcı bir düz-metin snapshot'ı hemen düşürmek isterseniz `php artisan cache:forget settings`'i elle çalıştırmak zararsızdır.

## v13.6.8 → v13.6.9

### `CheckResourcePermission` artık staging/demo'da fail-closed (davranış değişikliği)

Bu bir **runtime** (`src/`) değişikliğidir; yalnız `composer update` ile taşınır — publish edilmiş dosya değişmez, `sk:update` gerekmez. Buraya, production dışı host'larda bilinçli, güvenlik amaçlı bir **davranış değişikliği** olduğu için eklenmiştir.

**Önce:** middleware bir route'u DB'de **seed'lenmemiş** bir izne çözdüğünde, production *dışındaki* her ortamda (staging, uat, demo, `testing`) isteği bir uyarı logu ile **geçiriyordu** — yalnız `production` reddediyordu. Böylece public bir staging/demo host'u, izin satırı unutulmuş bir endpoint'i sessizce açığa çıkarabiliyordu.

**Sonra:** seed'lenmemiş bir izin, `local` dışındaki her ortamda **reddedilir**. `local` yine uyarıp geçirir; böylece henüz seed'lenmemiş bir izin günlük geliştirmeyi bloklamaz.

**Fark edebilecekleriniz:** public bir staging / uat / demo dağıtımında, izni (`php artisan sk:seed-permissions` ile) seed'lenmemiş bir route artık sessizce geçmek yerine **403** döner. Çözüm izni seed'lemektir — ki production zaten bunu gerektiriyordu.

**Opt-out (eski davranışa dönüş):** production dışı ortamlarda eski "geçir" davranışını bilinçli olarak istiyorsanız `.env`'e ekleyin:

```dotenv
STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS=true
```

(veya `config(['starter-kit.permissions.allow_unmapped' => true])`). Bu bayrak ne olursa olsun production her zaman reddeder; `local` her zaman geçirir.

**Bu değişiklikte ayrıca:** middleware'in seed'lenmiş izin sorgusu artık Octane-güvenli — izin adı kümesini tüm worker ömrü yerine kısa TTL (60sn) ile cache'ler ve `sk:seed-permissions` seed sonrası bu cache'i hemen temizler; böylece yeni seed'lenen izin, bayat bir worker geri dönüşene kadar beklemek yerine anında etkili olur.

**Cache-store bağımlılığı:** seed'lenmiş izin kontrolü artık bir container-instance binding yerine `Cache::remember()` (uygulamanızın yapılandırılmış varsayılan cache store'u) üzerinden çalışıyor. Networked bir cache store (Redis, Memcached, …) kullanıyorsanız, izin kontrolü yolu artık cache-miss durumunda o store'a dokunuyor — cache trafiğini izliyorsanız veya paylaşımlı/clustered bir store kullanıyorsanız bilmekte fayda var. `file` veya `array` cache driver'ındaki projelerde davranış farkı yok.

---

## v13.6.7 → v13.6.8

### Özet

Birkaç publish edilmiş stub dosyasına dokunan bir kalite/UX turu. Başlıca değişiklik bir güvenlik düzeltmesi: `auth.login_throttle = '0'` artık web login rate limiter'ını tamamen devre dışı bırakmıyor — bunun yerine bilinçli olarak gevşek bir taban limiter'a geçiyor. Bu sürümdeki diğer her şey (audit-log genişletmesi, `sk:install`/`sk:doctor`/`sk:eject` DX iyileştirmeleri, form/datatable erişilebilirliği) `src/` altında (vendor runtime) yaşıyor ve yalnızca `composer update` yeterli — tam liste için `CHANGELOG.md`'ye bakın. Aşağıdaki adımları bir kez çalıştırın; sonraki bölümler referans detaydır.

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # güncellenmiş SettingsServiceProvider/FortifyServiceProvider, eslint.config.js, vitest.config.ts, Definition model, datatable.css'i teslim eder
npm run build
```

### `login_throttle = '0'` artık web login limiter'ını tamamen devre dışı bırakmıyor

**Öncesi:** Ayarlar → Güvenlik'te `auth.login_throttle` değerini `'0'` yapmak Fortify'nin `login` rate limiter'ını tamamen null'a çekiyordu — bu tek ayarda `0` değeri web login'in sınırsız denemeyi kabul etmesine yol açıyordu.

**Sonrası:** `stubs/app/Providers/SettingsServiceProvider.php` artık sert `login` limiter'ını null'a çekmek yerine yeni bir `login-relaxed` limiter'a çeviriyor (tanımı `stubs/app/Providers/FortifyServiceProvider.php`'de). Web login bir yönetici tarafından gevşetilebilir, ama asla tam limitsiz kalmaz. API auth route'ları her iki durumda da etkilenmez — kendi sabit `throttle:5,1` middleware'ini taşırlar.

**`SettingsServiceProvider.php` veya `FortifyServiceProvider.php`'yi özelleştirmediyseniz:** `sk:update` her iki değişikliği de otomatik teslim eder; yukarıdaki standart yükseltme adımlarının dışında bir işlem gerekmez.

**Her ikisini de özelleştirdiyseniz:** `sk:update` sizin versiyonunuzu korur ve hash farkını raporlar. İki değişikliği elle uygulayın:
- `FortifyServiceProvider::boot()`'a bir `login-relaxed` `RateLimiter::for(...)` tanımı ekleyin (kesin limitler için vendor stub'a bakın).
- `SettingsServiceProvider`'da, `auth.login_throttle === '0'` iken `config(['fortify.limiters.login' => null])` satırını `config(['fortify.limiters.login' => 'login-relaxed'])` olarak değiştirin.

Projenize özel bir sebepten tamamen limitsiz bir login limiter'ı isterseniz, kendi kopyanızda doğrudan `config(['fortify.limiters.login' => null])` ayarlamaya devam edebilirsiniz — bu artık kit'in varsayılan davranışı değildir.

### İlgili küçük değişiklikler

- **`stubs/eslint.config.js` ruleset'i yükseltildi** — `pluginVue` flat config `essential`'dan `strongly-recommended`'a taşındı. `sk:update` yeni dosyayı teslim eder; özelleştirmediyseniz, güncelledikten sonraki ilk `npm run lint` çalıştırmasında yeni (önceden var olan) Vue stil uyarıları görebilirsiniz. Kendi hızınızda düzeltin ya da kuralı kendi kopyanızda `warn`'a sabitleyin.
- **Vitest config `vite.config.ts`'den ayrıştırıldı** — inline `test: {...}` bloğu yeni bir `stubs/vitest.config.ts`'e taşındı. `sk:update` iki dosyayı birlikte teslim eder; `vite.config.ts`'i özelleştirdiyseniz yeni `vitest.config.ts`'i elle yanına ekleyin (varsayılan `environment`/`globals` değerleri için vendor stub'a bakın).
- **API iki faktör challenge'ları artık atomik olarak sahipleniliyor** — `app/Domain/Auth/Actions/TwoFactorChallengeAction.php` artık `Cache::pull()` çağrısını sahiplenme olarak kullanmıyor. `Cache::pull()` her cache sürücüsünde ayrı bir get + forget'tir; bu yüzden tek bir challenge id'sinin eşzamanlı iki denemesi aynı kullanıcı id'sini okuyup iki access token üretebiliyordu ve route'taki `throttle:5,1` bu yarışı daraltsa da sıraya sokmuyor. Action artık challenge'ı bir yardımcı anahtar üzerinden (`api:2fa_challenge_claimed:{uuid}`) `Cache::add()` ile sahipleniyor — store içinde atomik olan "yoksa ekle" işlemi — ve payload'ı yalnızca kazanan okuyor. `Cache::lock()` yerine `Cache::add()` bilinçli bir tercih: `database` cache sürücüsünde lock ayrı bir `cache_locks` tablosuna ihtiyaç duyar ve bu tabloyu oluşturmamış bir kurulum 2FA endpoint'inde sert bir hata alırdı. Yapılandırma değişikliği veya yeni tablo gerekmiyor; tek kullanımlık davranış da aynı kalıyor (yanlış kod challenge'ı yine tüketir). **`TwoFactorChallengeAction.php` veya `LoginUserAction.php` dosyalarını özelleştirdiyseniz,** `sk:update` kopyalarınızı korur ve hash farkı raporlar — değişikliği elle taşıyın: `LoginUserAction`, public bir `TWO_FACTOR_CHALLENGE_TTL` sabiti ile `challengeClaimKey()` yardımcısını kazandı ve `TwoFactorChallengeAction::execute()`, başka hiçbir şey okumadan önce `Cache::add(LoginUserAction::challengeClaimKey($challenge), true, LoginUserAction::TWO_FACTOR_CHALLENGE_TTL)` false döndüğünde `null` dönmelidir.
- **`Definition` modeli bir cache-flush observer'ı kazandı** — `app/Models/Definition.php` artık her yazma yolunda (`saved`/`deleted`/`restored`/`forceDeleted`) definition cache'ini flush ediyor; bu, seeder dışında bir yolla Definition yazmanın ~1h TTL'ye kadar bayat cache bırakabildiği bir hatayı düzeltiyor. Eski (hatalı) bayatlığa güveniyorduysanız dışında görünür bir değişiklik yok.
- **Datatable inline arama-temizle / filtre-kaldırma markup'ı** — `stubs/resources/css/theme/main/components/datatable.css`, klavye erişilebilirliği için altındaki icon-only `<span>`'i gerçek bir `<button>`'a çevirdi; CSS reset görsel olarak birebir aynı tutuyor. Standart `sk:update && npm run build` dışında bir işlem gerekmez.

---

## v13.5.11 → v13.6.0

### Özet

13.6.0, v13.5.11'den (son yayınlanan sürüm) bu yana publish edilmiş dosyalara dokunan tüm değişiklikleri tek bir geçişte toplar. Vendor-runtime migrasyonunu tamamlar — backend yardımcı sınıfları, middleware, üç üçüncü-parti config, 15 composable, `TurnstileWidget.vue` ve `v-can` / `v-role` izin direktif plugin'i artık vendor paketinden çalışır — ve yapılandırılmış tema/layout/CSS sistemini getirir: bir `AppShell.vue` kompozisyonu, `themes/main/` slot ağacı (her CSS cascade katmanı override edilebilir bir slot) ve opt-in `themes/custom/` override teması. Ayrıca Güvenlik Ayarları yeniden tasarımını getirir: Güvenlik sekmesi üç alt sekmeye ayrılır (Kimlik Doğrulama / Parola Politikası / Cloudflare Turnstile), altı yeni `auth.*` ayar anahtarı eklenir ve parola kuralları ile parola geçerlilik süresi `EnsurePasswordNotExpired` middleware'i aracılığıyla tam olarak uygulamaya alınır. **Varsayılan build'de görsel değişiklik yoktur** — varsayılan build (`VITE_SK_THEME=main`) güvenlik ayarlarına dokunmayan projeler için v13.5.11 ile byte-identical'dır. Aşağıdaki adımlarla geçişi tek seferde yapın; ardından gelen alan-bazlı bölümler referans detaydır (yalnızca projenize uyan "özelleştirdiyseniz…" notlarını uygulayın).

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update          # yeni stub'ları getirir: layout, CSS tema ağacı, resolver, .env.example + package.json güncellemeleri
php artisan migrate            # users tablosuna password_changed_at kolonu ekler
npm install
npm run build                  # panel birebir aynı görünmeli
```

---

### Runtime tema geçişi — `main` ve `aura`

**Ayarlar → Görünüm**'de tema seçimi artık iki yerleşik kit teması (`main` ve `aura`) için anında uygulanır — derleme gerekmez. Her ikisi de her zaman bundle'a dahildir; `aura`, yeni `useTheme` composable'ının runtime'da `<html>` üzerine yazdığı `data-sk-theme="aura"` attribute'u ile etkinleşir.

| Tema | Nasıl etkinleşir | Derleme gerekli mi? |
|---|---|---|
| `main` | Varsayılan — `data-sk-theme` attribute'u yok | Hayır |
| `aura` | `useTheme` tarafından `<html>` üzerine `data-sk-theme="aura"` yazılır | Hayır |
| Custom (consumer tarafından oluşturulan) | `.env`'de `VITE_SK_THEME=<isim>` | Evet |

#### Mevcut kurulumlar

`php artisan sk:update && npm run build` komutlarını çalıştırın (standart v13.6.0 geçişi). Güncellenmiş `AdminLayout.vue` stub'ı, mevcut `useDarkMode()` ve `useAccentColor()` çağrılarının yanına `useTheme()` çağrısı ekler. Build sonrasında Ayarlar → Görünüm'de `main` ile `aura` arasında geçiş yapmak anında gerçekleşir.

`resources/css/theme/<isim>/` altında oluşturduğunuz custom temalar eskisi gibi çalışmaya devam eder — build-zamanı slot resolver'ı değişmemiştir.

#### `aura` CSS `theme-runtime/`'a taşındı

Daha önce `resources/css/theme/aura/` konumundaki aura CSS dosyaları `resources/css/theme-runtime/aura/` konumuna taşındı. Tüm kurallar artık `html[data-sk-theme='aura']`'ya scope'ludur.

`sk:update`, yeni `theme-runtime/aura/` ağacını getirir ve eski `theme/aura/` dizinini kaldırır. `theme.css` giriş dosyası artık iki import içerir:

```css
@import './_active.css';
@import '../theme-runtime/aura/aura.css';
```

**`theme/theme.css`'i özelleştirdiyseniz:** `sk:update` sonrasında ikinci import'u yeniden ekleyin. `sk:update`, `theme.css` için hash farkı raporlar; iki-import modelini kopyanıza elle uygulayın.

**`VITE_SK_THEME=aura` kullanıyorsanız:** bu değişkeni kaldırın — artık istenen etkiyi üretmemektedir. `aura` teması artık slot tabanlı bir build-zamanı teması değildir; yalnızca Ayarlar → Görünüm üzerinden runtime'da etkinleşir. `VITE_SK_THEME=aura` ayarıyla resolver `24 slots, 0 overrides` üretir (aura slot ağacında değil) ve `aura` görsel stili yine de etkinleşir — ancak `_active.css` üzerinden değil, yalnızca runtime attribute üzerinden.

#### Görsel değişiklik yok

`main` (varsayılan) kullanan projelerde build sonrası görsel çıktı byte-identical'dır. `VITE_SK_THEME=aura` kullanan projeler için değişkeni kaldırın, yeniden derleyin ve Ayarlar → Görünüm üzerinden `aura`'ya geçin — sonuç görsel olarak aynıdır.

#### İlgili küçük değişiklikler

- **`sk:doctor` Theme Manifest kontrolü** artık `_active.css` başlığındaki tema `VITE_SK_THEME` ile uyuşmadığında uyarı vermiyor. Runtime tema kaydı marker'ı rutin olarak `main`'e sıfırladığından bu karşılaştırma sistematik yanlış pozitif üretirdi. Eksik manifest hard-fail'i ve `../` traversal uyarısı yerinde duruyor.
- **`.env.example` artık `VITE_SK_THEME` göndermiyor.** Değişken build-time resolver tarafından custom temalar için hâlâ tanınır (marker → `VITE_SK_THEME` → `main`) — custom tema kullanacaksanız `.env` dosyanıza kendiniz ekleyin.

---

### Kurulum anında domain eject'i (User + Role)

Bu sürümden itibaren `sk:install`, ilk çalıştırmada `User` ve `Role` domain runtime'ını otomatik olarak `app/Domain/User/` ve `app/Domain/Role/` altına eject eder. Bu değişiklik yalnızca yeni kurulumları etkiler — **mevcut kurulumlar etkilenmez**.

#### Mevcut kurulumlar — işlem gerekmez

Eject adımı hash registry ile korunmaktadır: yalnızca `storage/starter-kit/hashes.json` henüz yoksa (yani ilk kurulumda) çalışır. Mevcut kurulumda registry zaten mevcut olduğundan adım tamamen atlanır. `app/Domain/{User,Role}/Actions` daha önce yapılan manuel bir eject nedeniyle zaten mevcutsa, o domain'in eject'i uyarıyla atlanır ve kurulumun geri kalanı normal şekilde devam eder.

#### Bu sürümdeki yeni kurulumlar

`app/Domain/User/` ve `app/Domain/Role/`, backend sınıfları `App\Domain\` namespace'iyle yeniden yazılmış olarak oluşturulur; `DomainServiceProvider` altı audit-event `Event::listen` binding'ini alır. Takas: bu dosyalar artık size ait — `composer update` bu dosyalara upstream değişiklik göndermez. Ayrıntılar için [artisan-commands.tr.md](./artisan-commands.tr.md) içindeki `sk:eject` güncelleme-kaybı notuna bakın.

Kurulum sırasında devre dışı bırakmak için:

```bash
php artisan sk:install --without-eject
```

#### Otomatik eject'i geri alma

```bash
rm -rf app/Domain/User/ app/Domain/Role/
# User ve Role için enjekte edilen Event::listen satırlarını kaldırın:
# app/Providers/DomainServiceProvider.php
composer dump-autoload
```

`StarterKitServiceProvider` içindeki `class_alias` tanımları, `App\Domain\User\*` ve `App\Domain\Role\*` import'larını otomatik olarak tekrar vendor kopyalarına yönlendirir.

---

### Davranış-modülü HTTP + Vue katmanları vendor'a taşındı (v13.6.0)

Beş davranış modülünün — **Files, Logs, ActivityLogs, ApiRoutes, Settings** — HTTP katmanı (controller'lar + FormRequest'ler) ve Vue admin sayfaları uygulamanızdan vendor paketine taşındı. Fresh install'da bu dosyalar artık `app/`'e kopyalanmıyor. Mevcut kurulumda `sk:update` bunları hash guard altında otomatik olarak migrate eder (aşağıya bakın).

#### Modül bazında ne değişti

| Modül | Uygulamanızdaydı | Artık vendor-resident |
|---|---|---|
| Files (File Manager) | `resources/js/pages/Admin/Files/` (yalnızca Vue — backend zaten vendor'daydı) | Vue sayfaları `app.ts` fallback aracılığıyla paketten sunulur |
| Logs | `app/Http/Controllers/Admin/LogController.php`, `app/Http/Requests/Admin/Log/`, `resources/js/pages/Admin/Logs/` | controller + request'ler + Vue sayfaları |
| Activity Logs | `app/Http/Controllers/Admin/ActivityLogController.php`, `resources/js/pages/Admin/ActivityLogs/` | controller + Vue sayfaları |
| API Routes | `app/Http/Controllers/Admin/ApiRouteController.php`, `resources/js/pages/Admin/ApiRoutes/` | controller + Vue sayfaları |
| Settings | `app/Http/Controllers/Admin/SettingsController.php`, `app/Http/Requests/Admin/Settings/`, `resources/js/pages/Admin/Settings/` | controller + request'ler + Vue sayfaları |

#### Vendor çözümlemesi nasıl çalışır

- **Controller'lar + FormRequest'ler**, `StarterKitServiceProvider::backwardCompatAliasPlan()` aracılığıyla çözülür: `App\Http\Controllers\Admin\SettingsController` (ve diğer dördü) `Lvntr\StarterKit\Http\...` karşılıklarına alias'lanır. Bir `app/Http/Controllers/Admin/SettingsController.php` dosyası var olduğu anda alias devre dışı kalır — böylece mevcut herhangi bir app kopyası hiçbir import değişikliği olmadan kazanmaya devam eder.
- **Vue sayfaları**, `app.ts` vendor-fallback loader'ı aracılığıyla çözülür: `import.meta.glob('@lvntr/pages/...')`, yerel `resources/js/pages/` glob'undan sonra kontrol edilir. Yerel bir dosya mevcut olduğunda app-önce glob her zaman kazanır.

#### `app.ts` vendor-sayfa fallback gerekliliği

Vue migrasyonu, `resources/js/app.ts`'in `@lvntr/pages` vendor glob'unu içermesine bağlıdır. `sk:update`, vendor-migrate edilmiş herhangi bir Vue dosyasını kaldırmadan önce bu marker'ı kontrol eder; yoksa Vue grupları bir uyarıyla yerinde bırakılır:

```
 WARN  app.ts does not contain the @lvntr/pages vendor fallback — Vue migration skipped.
       Run `php artisan sk:update` after updating app.ts to complete the migration.
```

Bu uyarıyı görürseniz, `app.ts`'inize vendor-sayfa resolver'ını ekleyin. Güncellenmiş stub `sk:update` tarafından sağlanır — hash-tracked değişikliği `app.ts`'e uygulayın ve `sk:update`'i tekrar çalıştırın.

#### Mevcut kurulumlar — `sk:update` ne yapar

`sk:update`, dosyaları **modül grubu** bazında, iki bağımsız katmanda (PHP ve Vue) migrate eder:

- **Değiştirilmemiş kopyalar** (disk üzerindeki hash registry kaydıyla eşleşir): silinir. Vendor kopyası devralır — controller alias köprüsü aracılığıyla, Vue sayfaları `app.ts` fallback aracılığıyla.
- **Değiştirilmiş kopyalar** (hash farklı ya da registry kaydı yok): yerinde tutulur, korunmuş olarak raporlanır. Özelleştirilmiş dosyanız vendor kopyasını kazanmaya devam eder.
- **Grup atomikliği**: bir modülün PHP katmanındaki tek bir dosya bile değiştirilmişse, o modülün tüm PHP katmanı korunur (ör. özelleştirilmiş bir `SettingsController.php`, eşleşen `app/Http/Requests/Admin/Settings/` dizinini de korur). PHP ve Vue katmanları bağımsız olarak değerlendirilir.

`sk:update` sonrasında çalıştırın:

```bash
npm run build
```

Migration, route değişikliği veya permission değişikliği gerekmez.

#### Üç yaygın senaryo

**Senaryo A — değiştirilmemiş kurulum (standart durum)**

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # hash-korumalı kaldırma — beş modülün tümü otomatik migrate olur
npm run build
```

`sk:update`, değiştirilmemiş app kopyalarını kaldırır ve vendor devralır. Başka işlem gerekmez.

**Senaryo B — bir veya birden fazla modülde değiştirilmiş dosya(lar)**

`sk:update`, korunan her modül grubunu raporlar:

```
 WARN  Vendor-migrated paths preserved (user-modified or untracked):
  • app/Http/Controllers/Admin/SettingsController.php (modified)
  • app/Http/Requests/Admin/Settings/ (preserved with controller)
  • resources/js/pages/Admin/Settings/ (modified)
```

Özelleştirilmiş dosyalarınız çalışmaya devam eder — siz migrate etmeyi seçene kadar işlem gerekmez. Özelleştirilmiş modülün tam sahipliğini açıkça almak için:

```bash
php artisan sk:eject Setting   # vendor controller + request'leri + Vue'yu App\ namespace'iyle uygulamanıza kopyalar
```

**Senaryo C — v13.6.0+'tan fresh install**

İşlem gerekmez. `sk:install`, beş modülün hiçbirini kopyalamaz. İlk günden itibaren vendor'dan çalışırlar.

#### Vendor-resident bir modülün tam sahipliğini alma

Bir modülün backend'ini (controller, FormRequest'ler) veya Vue sayfalarını vendor'a migrate olduktan sonra özelleştirmek için `sk:eject` kullanın:

```bash
php artisan sk:eject Logs             # backend + Vue sayfaları
php artisan sk:eject Logs --no-vue    # yalnızca backend
php artisan sk:eject Logs --dry-run   # önce önizleme
php artisan sk:eject Files            # yalnızca Vue sayfaları (Files backend'i her zaman vendor'da kalır)
```

Eject sonrasında `sk:update`, bu dosyaları consumer-owned olarak değerlendirir ve asla kaldırmaz. Tam `sk:eject` flag referansı ve güncelleme-kaybı takası için [artisan-commands.tr.md](./artisan-commands.tr.md)'ye bakın.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Route dosyaları (`routes/web/*-route.php`) | Değişmez — uygulamanızda kalır |
| Route dosyalarınızdaki `App\Http\Controllers\Admin\*` import'ları | Çalışmaya devam eder — alias köprüsü ya da ejected kopya bunları çözer |
| Permission key'leri, route isimleri | Değişmez |
| `config/permission-resources.php` | Değişmez — sanctuary, asla üzerine yazılmaz |
| User / Role / Dashboard / Auth / Profile modülleri | Değişmez — tamamen app-owned kalır |

---

### Davranış-modülü HTTP katmanı vendor'a taşındı — Faz 2 (v13.6.0)

Faz 1 (yukarıda), Files / Logs / ActivityLogs / ApiRoutes / Settings controller'larını ve Vue sayfalarını vendor'a taşıdı. Faz 2, **vendor Settings sekmelerini besleyen kalan controller'ları** ve zaten vendor servislerini saran iki API/Service controller'ını taşıyarak tabloyu tamamlar. Vue ve migration'lar zaten vendor'daydı (Faz 1'de sağlandı); Faz 2, **yalnızca PHP katmanı** taşımasıdır.

#### Modül bazında ne değişti

| Modül | Uygulamanızdaydı | Artık vendor-resident |
|---|---|---|
| API Clients | `app/Http/Controllers/Admin/ApiClientController.php`, `app/Http/Requests/Admin/ApiClient/`, `app/Http/Resources/Admin/ApiClient/` | controller + request'ler + resource |
| API Tokens | `app/Http/Controllers/Admin/ApiTokenController.php`, `app/Http/Requests/Admin/ApiToken/`, `app/Http/Resources/Admin/ApiToken/` | controller + request + resource |
| System Health | `app/Http/Controllers/Admin/SystemHealthController.php` | controller (domain / request / resource yok) |
| Definitions (API + Service) | `app/Http/Controllers/Api/DefinitionController.php`, `app/Http/Controllers/Service/DefinitionServiceController.php` | her iki controller (vendor `DefinitionService` zaten vendor'daydı) |
| Media upload/delete | `app/Http/Controllers/Api/MediaUploadController.php` | controller |
| Content Languages | `app/Domain/ContentLanguage/` (Actions/DTOs/Queries), `app/Http/Controllers/Admin/ContentLanguageController.php`, `app/Http/Requests/Admin/ContentLanguage/`, `app/Http/Resources/Admin/ContentLanguage/` | domain runtime + controller + request'ler + resource |

#### Vendor çözümlemesi nasıl çalışır

Faz 1 ile aynıdır: taşınan her controller / FormRequest / Resource, `StarterKitServiceProvider::backwardCompatAliasPlan()` tarafından `App\Http\...` FQCN'inden `Lvntr\StarterKit\Http\...` karşılığına, bir `file_exists` guard'ı altında alias'lanır — bir `app/Http/Controllers/Admin/ApiClientController.php` (ya da başka herhangi biri) dosyası var olduğu anda alias kenara çekilir ve sizin kopyanız kazanır. `App\Domain\ContentLanguage\...` runtime sınıfları da aynı şekilde çözülür (`Lvntr\StarterKit\Domain\ContentLanguage\...`'a alias). Route dosyalarınız mevcut `App\Http\Controllers\...` import'larını değişmeden korur.

#### Model'ler app-owned kalır

`App\Models\ContentLanguage`, `App\Models\Media` ve `App\Models\Definition` vendor'a **taşınmaz** ve alias'lanmaz — bir model'i taşımak Laravel'in `XPolicy` keşfini ve route-model binding'ini kırardı. Vendor `ContentLanguageController`, `MediaUploadController` ve `DefinitionController` bu model'lere `App\` FQCN'leriyle referans verir; ejected `app/Domain/ContentLanguage` runtime'ı `App\Models\ContentLanguage` referansını değişmeden korur. `content_languages` ve `media` migration'ları zaten vendor'dadır (Faz 4) — Faz 2'de migration değişikliği yoktur.

#### Mevcut kurulumlar — geçiş adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # artık-vendor PHP kopyalarının hash-korumalı kaldırması
```

`sk:update`, Faz 2 PHP katmanının değiştirilmemiş app kopyalarını modül grubu bazında kaldırır (Faz 1 ile aynı grup-atomik kural: bir modülün PHP katmanındaki herhangi bir değiştirilmiş dosya tüm katmanı korur). Yalnızca Faz 2 için Vue yeniden build'i gerekmez — Vue Faz 1'de migrate olmuştu — ancak tam v13.6.0 geçişinden sonra `npm run build` çalıştırmak doğru tek adım olmaya devam eder.

#### Tam sahipliği alma

```bash
php artisan sk:eject ApiClient          # ApiClient + ApiToken controller'ları + request'leri + resource'ları
php artisan sk:eject ContentLanguage    # domain + controller + request + resource
php artisan sk:eject SystemHealth       # yalnızca controller
php artisan sk:eject Definitions        # Api + Service controller'ları (DefinitionService vendor'da kalır)
php artisan sk:eject MediaUpload        # yalnızca controller (routes/web.php içindeki media.destroy route'u)
```

Eject sonrasında `sk:update`, bu dosyaları consumer-owned olarak değerlendirir ve asla kaldırmaz. Tam eject domain tablosu ve güncelleme-kaybı takası için [artisan-commands.tr.md](./artisan-commands.tr.md)'ye bakın.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Route dosyaları (`routes/web/*-route.php`, `routes/web.php`, `routes/api/service-route.php`) | Değişmez — uygulamanızda kalır; yalnızca controller `use` import'u vendor'ı gösterir |
| Permission key'leri, route isimleri | Değişmez — route isimleri `CheckResourcePermission`'ı sürer; hiçbir şey yeniden adlandırılmadı |
| Passport client/token secret tek-sefer-gösterim | Değişmez — `ApiClientController` / `ApiTokenController` logic'i byte-identical, yalnızca dosya konumu taşındı |
| `App\Models\{ContentLanguage,Media,Definition}` | Asla taşınmaz, asla alias'lanmaz — app-owned |
| `RoleServiceController` | Değişmez — Role/Setting scaffold ekranlarını besler, app-owned kalır |
| `LocaleController`, `Api/UserController`, `Api/Auth/*`, Dashboard / User / Role / Profile / Auth controller'ları | Değişmez — scaffold, tamamen app-owned |

---

### Domain runtime katmanları vendor'a taşındı (Faz 6)

Beş domain modülünün **runtime katmanı** (Actions, DTOs, Queries, Events, Listeners ve Setting servisi), `stubs/app/Domain/` yerine pakete (`src/Domain/`, PSR-4 `Lvntr\StarterKit\Domain\`) taşındı. Tüketici yüzeyi — Controller'lar, FormRequest'ler, Model'ler, Vue sayfaları, route dosyaları, Policy'ler ve `config/settings.php` — uygulamanızda kalmaya devam eder ve **etkilenmez**.

Etkilenen domain'ler: `ApiClient`, `ApiRoute`, `Setting`, `User`, `Role`.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Controller / provider'larınızdaki `App\Domain\<Module>\...` import'ları | Çalışmaya devam eder — `class_alias` bunları vendor namespace'ine şeffaf biçimde çözer |
| Mevcut `app/Domain/{ApiClient,ApiRoute,Setting,User,Role}/` kopyaları | Korunur, otomatik silinmez |
| Controller'lar, FormRequest'ler, Model'ler, Vue sayfaları, route dosyaları | Değişmez — uygulamanızda kalır |
| `App\Models\User`, `App\Models\Role`, `App\Models\Setting` | Asla vendor'a taşınmaz |
| `Store/UpdateRoleRequest` privilege-boundary (`validated()`) | Değişmez — app-owned |
| `config/permission-resources.php` | Değişmez — sanctuary (`sk:update` asla üzerine yazmaz) |
| `config/settings.php` | Değişmez — sanctuary (`sk:update` asla üzerine yazmaz, bu sürümde eklendi) |
| Policy'ler (`UserPolicy`, `RolePolicy`, `SettingPolicy`, `ApiClientPolicy`, `ApiTokenPolicy`) | Değişmez — app-owned |
| Postman / Apidog console command'ları | Değişmez — app-owned |
| `BulkDeleteUserAction`, `BulkDeleteRoleAction` | Değişmez — app-owned (app-owned `App\Http\BulkActions\BulkDeleteAction` override base sınıfını extend eder) |
| Permission key'leri, route isimleri, API zarfı | Değişmez |
| `RoleEnum` (system_admin / admin / user sözleşmesi) | Değişmez — app-owned |

#### Yeni kurulumlar (v13.6.0+)

`sk:install` artık `app/Domain/ApiClient/`, `app/Domain/ApiRoute/`, `app/Domain/Setting/`, `app/Domain/User/Actions/`, `app/Domain/User/DTOs/`, `app/Domain/User/Events/`, `app/Domain/User/Listeners/`, `app/Domain/User/Queries/`, `app/Domain/Role/Actions/`, `app/Domain/Role/DTOs/`, `app/Domain/Role/Events/`, `app/Domain/Role/Listeners/` veya `app/Domain/Role/Queries/` dizinlerini `app/`'e kopyalamaz. Bu modüllerin runtime sınıfları doğrudan `vendor/lvntr/laravel-starter-kit/src/Domain/`'dan çalışır. Scaffold controller'larındaki `App\Domain\<Module>\...` import'ları `class_alias` aracılığıyla çözülür — üretilen kodda herhangi bir import değişikliği gerekmez.

#### Mevcut kurulumlar — geçiş adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update`, uygulamanızda kalan vendor-resident app kopyalarını raporlar (yalnızca bilgilendirici — otomatik olarak asla silinmez):

```
 WARN  v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Domain/ApiClient/
  • app/Domain/ApiRoute/
  • app/Domain/Setting/
  • app/Domain/User/Actions/
  • app/Domain/User/DTOs/
  • app/Domain/User/Events/
  • app/Domain/User/Listeners/
  • app/Domain/User/Queries/
  • app/Domain/Role/Actions/
  • app/Domain/Role/DTOs/
  • app/Domain/Role/Events/
  • app/Domain/Role/Listeners/
  • app/Domain/Role/Queries/

  Deleting these files is optional; vendor copies take precedence.
```

Başka herhangi bir adım gerekmez. Uygulamanız değişmeden çalışmaya devam eder.

#### İsteğe bağlı temizlik — eski app kopyalarını reconcile etme

Bu adım tamamen isteğe bağlıdır ve daha sonra da yapılabilir.

**Bu domain dosyalarının hiçbirini özelleştirmediyseniz**, app kopyalarını silerek vendor versiyonlarının (`class_alias` aracılığıyla) devreye girmesini sağlayabilirsiniz:

```bash
# ApiClient + ApiRoute
rm -rf app/Domain/ApiClient/
rm -rf app/Domain/ApiRoute/

# Setting runtime (app/Models/Setting.php ve app/Policies/SettingPolicy.php'yi koruyun)
rm -rf app/Domain/Setting/

# User runtime (app/Domain/User/BulkActions/'ı koruyun)
rm -rf app/Domain/User/Actions/
rm -rf app/Domain/User/DTOs/
rm -rf app/Domain/User/Events/
rm -rf app/Domain/User/Listeners/
rm -rf app/Domain/User/Queries/

# Role runtime (app/Domain/Role/BulkActions/'ı koruyun)
rm -rf app/Domain/Role/Actions/
rm -rf app/Domain/Role/DTOs/
rm -rf app/Domain/Role/Events/
rm -rf app/Domain/Role/Listeners/
rm -rf app/Domain/Role/Queries/
```

**Bir domain dosyasını özelleştirdiyseniz**, `app/Domain/<Module>/` dizinini veya ilgili alt dizini koruyun. `class_alias` guard'ı bunu algılar ve devre dışı kalır — özelleştirilmiş versiyonunuz vendor kopyasını kazanmaya devam eder. Değiştirilmemiş alt dizinleri silerken değiştirilmişleri saklayabilirsiniz; guard sınıf düzeyinde çalışır.

**Kısmi reconcile örneği** — özelleştirilmiş bir Action'ı koruyun, geri kalanları silin:

```bash
# User Actions'dan yalnızca özelleştirilmiş dosyayı bırakın
rm app/Domain/User/Actions/DeleteUserAction.php
rm app/Domain/User/Actions/UpdateUserAction.php
# Koru: app/Domain/User/Actions/CreateUserAction.php (özelleştirilmiş)
```

#### Domain bazında override yolları

Taşınan tüm runtime sınıfları `overridable` tier'dadır: `app/Domain/<Module>/<Class>.php` dosyası mevcutsa vendor kopyasını geçersiz kılar — alias guard otomatik olarak devre dışı kalır. Açık bir opt-in gerekmez.

| Katman | Override yolu |
|---|---|
| Action, DTO, Query, Event, Listener, Service | `App\Domain\<Module>\...` namespace'iyle `app/Domain/<Module>/<path>.php` oluşturun — alias guard vendor versiyonunu otomatik olarak atlar |
| Controller, FormRequest, Resource, Policy | Zaten app-owned — doğrudan düzenleyin |
| `config/settings.php` | Zaten app-owned sanctuary — doğrudan düzenleyin |
| Vue sayfaları (`resources/js/pages/Admin/*`) | Zaten app-owned — doğrudan düzenleyin |
| Runtime sınıfını yeniden yayınlama | PHP runtime sınıfları için `sk:publish <domain>` tag'i yoktur. `vendor/lvntr/laravel-starter-kit/src/Domain/<Module>/` konumundan `app/Domain/<Module>/` konumuna `App\Domain\<Module>\` namespace'iyle kopyalayın; alias guard gerisini halleder |

#### User / Role audit log event wiring

`UserCreated`, `UserUpdated`, `UserDeleted`, `RoleCreated`, `RoleUpdated` ve `RoleDeleted` event'leri ile `Log*` listener'ları artık vendor-resident'tır. `StarterKitServiceProvider`, event→listener bağlamalarını doğrudan kaydeder; `DomainServiceProvider`'daki bu altı çift için kayıtlar artık gerekli değildir.

**Fresh install:** `DomainServiceProvider`, bu altı çift için artık `Event::listen` çağrısı içermez — vendor provider bunları işler.

**Mevcut kurulum:** `app/Providers/DomainServiceProvider.php` içinde hâlâ bu çiftler için `Event::listen` kayıtları varsa (ör. `Event::listen(UserCreated::class, LogUserCreated::class)`), app kopyalarını reconcile ettikten sonra bu kayıtları kaldırın. App kopyaları mevcutken kayıtların tutulması zararsızdır — alias guard vendor binding'i atlar ve app tarafındaki kayıt tam olarak bir kez çalışır. Tamamen vendor'a geçmek için app kopyalarını ve `DomainServiceProvider` kayıtlarını birlikte kaldırın.

#### `config/settings.php` never-update sanctuary'sine eklendi

`config/settings.php` artık `NEVER_UPDATE_PATHS` listesindedir. `sk:update` bu dosyanın üzerine asla yazmaz; eklediğiniz sensitive key'ler veya setting grupları korunur. Bu, v13.6.0'dan itibaren geçerlidir — elle bir işlem gerekmez.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Auth / permission davranışı | Değişmez — `CheckResourcePermission`, `permission-resources.php`, `RoleEnum` dokunulmadı |
| API secret — Passport `plainSecret` tek-seferlik kuralı | Değişmez — secret-üreten yüzey app-owned `ApiClientController` ve `ApiClientResource`'da kalır |
| Setting şifreleme — `SettingService` `sensitive_keys` okuma / `Crypt::encryptString` yazma | Değişmez — logic aynı; yalnızca dosya konumu taşındı |
| `config/settings.php` sensitive-keys whitelist | Değişmez — app-owned ve artık sanctuary |
| Route dosyaları ve middleware tier'ları | Değişmez — tüm route dosyaları app-owned; route registry değişmedi |

---

### Kit çevirileri vendor'a taşındı (Faz 5)

44 adet kit-özel çeviri dosyası (`sk-*.php`, iki locale) `stubs/lang/` yerine paket içine (`resources/lang/{en,tr}/sk-*.php`) taşındı. Önceden derlenmiş JSON (`resources/js/lang/php_en.json` / `php_tr.json`) bunlarla birlikte dağıtılıyor ve frontend i18n setup'ı tarafından otomatik tüketiliyor. Çeviri dosyaları artık fresh install'da uygulamanıza toplu olarak kopyalanmıyor.

#### Çeviriler nasıl dağıtılıyor

| Katman | v13.6.0 öncesi | v13.6.0+ |
|---|---|---|
| Frontend (`$t('sk-common.*')`) | `app/lang/*.php` Vite plugin tarafından derlenir | Vendor JSON, build zamanında `app/lang` ile merge edilir — çakışmada app kazanır |
| PHP backend (`__('sk-common.*')`) | `sk:install`'ın kopyaladığı `app/lang/{locale}/sk-*.php` | `StarterKitServiceProvider` boot'ta vendor `resources/lang/{locale}/sk-*.php`'yi kaydeder |

#### Merge önceliği

Frontend i18n setup'ı (`resources/js/app.ts`) artık iki kaynak yükler:

1. **Vendor JSON** — `vendor/lvntr/laravel-starter-kit/resources/js/lang/php_{locale}.json` (uygulamanızda override edilmeyen her key için fallback)
2. **App JSON** — Vite i18n plugin'inin `app/lang/*.php`'den ürettiği `lang/php_{locale}.json` (öncelikli — özelleştirmeleriniz her zaman kazanır)

Eksik çeviriler vendor varsayılanına düşer. Bir `sk-*` key'ini özelleştirmediyseniz görünür bir değişiklik olmaz; özelleştirdiyseniz kendi versiyonunuz gösterilmeye devam eder.

#### Yeni kurulumlar (v13.6.0+)

`sk:install` artık `lang/{en,tr}/sk-*.php` dosyalarını uygulamanıza kopyalamaz. Kit çevirileri vendor paketinden sunulur. `lang/{en,tr}/validation.php` hâlâ kopyalanır — bu, standart Laravel validation override yüzeyidir ve uygulamanızda kalmaya devam eder.

Ekstra bir adım gerekmez.

#### Mevcut kurulumlar — geçiş adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
npm run build
```

`sk:update`, uygulamanızda kalan `lang/{locale}/sk-*.php` kopyalarını raporlar (yalnızca bilgilendirici — **otomatik olarak asla silinmez**):

```
 WARN  These files are now vendor-resident. Your app copies are kept; vendor copies take precedence where no app copy exists.

  • lang/en/sk-activity-log.php
  • lang/en/sk-api-clients.php
  • ... (locale başına 22 dosya)

  Deleting these files is optional; if present, they continue to take precedence over the vendor default for any keys they define.
```

Uygulamanız güncellemeden sonra değişmeden çalışmaya devam eder. Frontend bundle'ında yeni vendor JSON kaynağını almak için `npm run build` komutunu çalıştırın.

#### İsteğe bağlı temizlik

Hiçbir `sk-*.php` çeviri dosyasını **özelleştirmediyseniz**, tamamen vendor varsayılanına bırakmak için app kopyalarını silebilirsiniz:

```bash
rm lang/en/sk-*.php
rm lang/tr/sk-*.php
```

Bir ya da birden fazla dosyayı **özelleştirdiyseniz**, bunları koruyun — ya da yalnızca değiştirdiğiniz dosyaları saklayın. `app/lang/{locale}/sk-*.php` içinde tanımlı her key, o key için vendor değerini geçersiz kılar. App kopyanızda bulunmayan key'ler vendor varsayılanına düşer.

#### Özelleştirme ve kaçış kapısı

Vendor çeviri dosyalarını tam özelleştirme için uygulamanıza yayınlamak üzere:

```bash
php artisan sk:publish lang
```

Bu komut, vendor `resources/lang/` içeriğini `lang/vendor/starter-kit/` konumuna kopyalar ve namespace'li `starter-kit::` çevirilerini kullanıma açar. Frontend ve backend'in kullandığı namespace-siz `sk-*` key'leri için override'larınızı doğrudan `lang/{locale}/sk-*.php` içine koyun — merge bunları otomatik alır.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Çeviri içeriği — tüm `sk-*` string'leri | Değişmez — yalnızca dosya konumu taşınır |
| `lang/{locale}/validation.php` | Değişmez — uygulamanızda kalır (Laravel framework override yüzeyi) |
| Permission key'leri, route isimleri, API zarfı | Değişmez |
| Frontend `$t('sk-*')` çağrı yerleri | Değişmez |
| Vendor runtime'daki PHP `__('sk-*')` çağrı yerleri | Değişmez — `StarterKitServiceProvider` aracılığıyla vendor `resources/lang/` üzerinden çözülür |

---

### Kit migration'ları vendor'a taşındı (Faz 4)

Altı kit-özel migration `stubs/database/migrations/` yerine paket içine (`database/migrations/`, `loadMigrationsFrom` ile otomatik yüklenir) taşındı. Artık fresh install'da uygulamanıza kopyalanmıyorlar.

#### Taşınan migration'lar

| Dosya | Tablo |
|---|---|
| `2026_03_08_205445_create_media_table.php` | `media` |
| `2026_03_11_071628_create_activity_log_table.php` | `activity_log` |
| `2026_03_12_001950_create_definitions_table.php` | `definitions` |
| `2026_03_14_080933_create_settings_table.php` | `settings` |
| `2026_04_13_100200_add_folder_id_to_media_table.php` | `media` (`folder_id` ekleme) |
| `2026_05_02_094121_add_soft_deletes_to_media_table.php` | `media` (`deleted_at` ekleme) |

Framework-default migration'lar (`create_users_table`, `create_cache_table`, `create_jobs_table`), Passport OAuth migration'ları ve Spatie permission migration'ı **taşınmadı** — `stubs/database/migrations/` içinde kalmaya devam ediyor ve uygulamanıza kopyalanmaya devam ediyor.

#### Nasıl çalışır

`config('starter-kit.run_migrations')` değeri `true` (varsayılan) olduğunda paket, vendor-resident migration'ları `loadMigrationsFrom` aracılığıyla otomatik yükler. Laravel migration geçmişini dosya adıyla (basename) takip ettiğinden, uygulamanızda zaten çalışmış bir migration sessizce atlanır — çift koşma veya hata olmaz.

#### Yeni kurulumlar (v13.6.0+)

`sk:install` artık yukarıda listelenen altı migration'ı kopyalamaz. Bu migration'lar doğrudan vendor paketinden çalışır. Ekstra bir adım gerekmez.

#### Mevcut kurulumlar — geçiş adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
```

`sk:update`, yukarıda listelenen altı app kopyasını force-delete eder. Bu güvenlidir: her dosya adı zaten `migrations` tablonuzda kayıtlıdır ve yeniden koşmaz (Laravel'in basename bazlı deduplication'ı). `php artisan migrate`, başka bekleyen migration yoksa "Nothing to migrate" döner.

#### Otomatik yüklemeyi devre dışı bırakma (kaçış kapısı)

Uygulamanızda fiziksel bir kopyaya ihtiyaç duyuyorsanız — örneğin bir migration'ı çalışmadan önce değiştirmek ya da statik analiz aracını tatmin etmek için — vendor migration'larını yayınlayın ve otomatik yüklemeyi kapatın:

```bash
php artisan vendor:publish --tag=starter-kit-migrations
php artisan vendor:publish --tag=starter-kit-config
```

Ardından `config/starter-kit.php` içinde `run_migrations` değerini `false` yapın:

```php
'run_migrations' => false,
```

Otomatik yükleme devre dışıyken paket `loadMigrationsFrom`'u hiç çağırmaz; yayınlanan kopyalar tek kaynak haline gelir. Bu bayrağı migration'ları yayınlamadan `false` yapmayın — aksi hâlde fresh install'da tablolar hiç oluşturulmaz.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Migration geçmişi (`migrations` tablosu) | Değişmez — basename'ler zaten kayıtlı |
| Şema — tablo, kolon, index | Değişmez — yalnızca dosya taşıması |
| Framework-default, Passport ve Spatie migration'ları | Değişmez — uygulamanızda kalmaya devam eder |
| Permission key'leri, route isimleri, API zarfı | Değişmez |

---

### Domain runtime katmanları vendor'a taşındı (Faz 3)

Dört domain modülünün **runtime katmanı** (Actions, DTOs, Queries, Events, Listeners, Services), `stubs/app/Domain/` yerine pakete (`src/Domain/`, PSR-4 `Lvntr\StarterKit\Domain\`) taşındı. Tüketici yüzeyi — Controller'lar, FormRequest'ler, Model'ler, Vue sayfaları ve route dosyaları — uygulamanızda kalmaya devam eder ve **etkilenmez**.

Etkilenen domain'ler: `ActivityLog`, `Logs`, `Session`, `Media`.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Controller / provider'larınızdaki `App\Domain\<Module>\...` import'ları | Çalışmaya devam eder — `class_alias` bunları vendor namespace'ine şeffaf biçimde çözer |
| Mevcut `app/Domain/{ActivityLog,Logs,Session,Media}/` kopyaları | Korunur, otomatik silinmez |
| Controller'lar, FormRequest'ler, Model'ler, Vue sayfaları, route'lar | Değişmez — uygulamanızda kalır |
| `App\Models\User` | Asla vendor'a taşınmaz |
| Kit migration'ları | v13.6.0'da vendor'a taşındı (Faz 4) — yukarıya bakın |
| Permission key'leri, route isimleri, API zarfı | Değişmez |

#### Yeni kurulumlar (v13.6.0+)

`sk:install` artık `app/Domain/ActivityLog/`, `app/Domain/Logs/`, `app/Domain/Session/` veya `app/Domain/Media/`'yı `app/`'e kopyalamaz. Bu modüllerin runtime sınıfları doğrudan `vendor/lvntr/laravel-starter-kit/src/Domain/`'dan çalışır. Scaffold controller'larındaki `App\Domain\<Module>\...` import'ları `class_alias` aracılığıyla çözülür — üretilen kodda herhangi bir import değişikliği gerekmez.

#### Mevcut kurulumlar — geçiş adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update`, artık vendor-resident olan uygulama kopyalarını raporlar (yalnızca bilgilendirici — otomatik silinmez):

```
 WARN  v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Domain/ActivityLog/
  • app/Domain/Logs/
  • app/Domain/Session/
  • app/Domain/Media/

  Deleting these files is optional; vendor copies take precedence.
  See: docs/migrate-existing-project-to-vendor.md
```

Başka bir adım gerekmez. Uygulamanız değişmeden çalışmaya devam eder.

#### İsteğe bağlı temizlik — eski uygulama kopyalarını reconcile etme

Bu adım tamamen isteğe bağlıdır, sonraya bırakılabilir.

**Bu domain dosyalarını hiç özelleştirmediyseniz**, uygulama kopyalarını silin; `class_alias` aracılığıyla vendor versiyonları devreye girer:

```bash
rm -rf app/Domain/ActivityLog/
rm -rf app/Domain/Logs/
rm -rf app/Domain/Session/
rm -rf app/Domain/Media/
```

**Bir domain dosyasını özelleştirdiyseniz**, `app/Domain/<Module>/` dizininizi koruyun. `class_alias` guard'ı bunu algılar ve kenara çekilir — özelleştirdiğiniz versiyon vendor kopyasının önünde çalışmayı sürdürür. Değiştirilmemiş dosyaları tek tek silebilir, değiştirilmiş olanları tutabilirsiniz; guard sınıf bazında çalışır.

**Kısmi reconcile örneği** — özelleştirilmiş bir Action'ı koruyup geri kalanı silme:

```bash
# Özelleştirdiğiniz dosya dışındaki her şeyi kaldırın
rm -rf app/Domain/Logs/DTOs/
rm -rf app/Domain/Logs/Events/
rm -rf app/Domain/Logs/Listeners/
rm -rf app/Domain/Logs/Queries/
rm -rf app/Domain/Logs/Services/
# Koru: app/Domain/Logs/Actions/DeleteLogFilesAction.php (özelleştirilmiş)
```

#### Session domain — `Authenticatable` decoupling

`PurgeOtherSessionsAction::execute()`, artık somut `App\Models\User` yerine `Illuminate\Contracts\Auth\Authenticatable` kabul eder. Metot yalnızca `Authenticatable` kontratının parçası olan `getAuthPassword()` ve `getAuthIdentifier()`'ı kullanır. `App\Models\User` örneği ileten çağrı yerleri etkilenmez — `User`, `Authenticatable`'ı uygular.

`ProfileController::destroySessions()` veya herhangi başka bir çağrı yerinde değişiklik gerekmez.

#### Logs domain — event listener kaydı

`LogFilesDeleted → LogActivityForLogFilesDeleted` listener'ı artık vendor `StarterKitServiceProvider` tarafından kaydedilir (hem event hem listener vendor-resident). Fresh kurulum scaffold'undaki `app/Providers/DomainServiceProvider.php` bu çifti artık kaydetmez: `class_alias`'lı bir `App\...::class` literali derleme zamanında *alias* adına çözülür ve bu, vendor event'inin çalışma-zamanı sınıfıyla asla eşleşmez — app tarafındaki kayıt "log dosyaları silindi" audit kaydını sessizce düşürürdü. **Fresh kurulumda ek işlem gerekmez.** Yükseltip kendi `app/Domain/Logs/` kopyalarınızı korursanız, mevcut `DomainServiceProvider` kaydınız onlar için çalışmaya devam eder (vendor kaydı dormant kalır — çift-tetikleme yok); reconcile ederseniz (app kopyalarını silerseniz) vendor kaydı artık-vendor dispatch'i karşılar.

---

### Build scriptleri vendor'a taşındı — consumer wiring güncellenmeli

`scripts/sk-theme-build.mjs` ve `scripts/vite-plugin-sk-theme.mjs` artık uygulamanıza kopyalanmıyor. Bu scriptler paket içinde taşınarak `vendor/lvntr/laravel-starter-kit/resources/js/theme/` üzerinden çözülüyor — `@lvntr/components` ve kit composable'larının kullandığı mekanizmanın birebir aynısı.

**Yeni kurulumlar** (`sk:install`) etkilenmez — scriptler zaten kopyalanmıyordu.

**Mevcut kurulumlar** için aşağıdaki adımları manuel uygulamanız gerekir.

#### Geçiş adımları

1. Paketi güncelleyin:

   ```bash
   composer update lvntr/laravel-starter-kit
   php artisan sk:update
   ```

   `sk:update`, uygulamanızdaki eski `scripts/sk-theme-build.mjs` ve `scripts/vite-plugin-sk-theme.mjs` kopyalarını otomatik siler. Bu dosyaları **değiştirmediyseniz** 3. adıma geçin.

2. **`scripts/sk-theme-build.mjs` veya `scripts/vite-plugin-sk-theme.mjs`'yi özelleştirdiyseniz** — bu dosyalar artık vendor tarafından yönetilmektedir. Özelleştirmelerinizi başka bir konuma taşımanız gerekir (örn. vendor versiyonunu içe alıp genişleten proje-yerel bir sarmalayıcı script). Resmi bir override hook'u için kit maintainer'ı ile iletişime geçin.

3. **`vite.config.ts`'i güncelleyin** — plugin import'unu eski yerel yoldan vendor yoluna çevirin:

   ```diff
   - import skTheme from './scripts/vite-plugin-sk-theme.mjs';
   + import skTheme from './vendor/lvntr/laravel-starter-kit/resources/js/theme/vite-plugin-sk-theme.mjs';
   ```

   > `vite.config.ts`'i özelleştirdiyseniz `sk:update` bu dosyayı otomatik güncellemez. Değişikliği elle uygulayın.

4. **`package.json` script'lerini güncelleyin** — `theme:build` script'i vendor yolunu göstermeli; `dev` ve `build` script'lerindeki açık `node scripts/...` önek'i artık gerekli değil (`skTheme()` Vite plugin'i tema üretimini Vite lifecycle içinde garanti ediyor):

   ```diff
   - "theme:build": "node scripts/sk-theme-build.mjs",
   - "dev": "node scripts/sk-theme-build.mjs && vite",
   - "build": "node scripts/sk-theme-build.mjs && vite build && vite build --ssr",
   + "theme:build": "node vendor/lvntr/laravel-starter-kit/resources/js/theme/sk-theme-build.mjs",
   + "dev": "vite",
   + "build": "vite build && vite build --ssr",
   ```

   > `skTheme()` plugin'i `_active.css`'i Vite'ın transform pipeline'ı içinde (`buildStart` / `configureServer` hook'ları) üretiyor; bu nedenle normal `dev` ve `build` çalıştırmalarında açık `&&` öneki artık gerekmez. Tam build yapmadan yalnızca `_active.css`'i yeniden üretmek için `npm run theme:build` kullanın.

5. Yeniden derleyin:

   ```bash
   npm run build
   ```

#### Görsel değişiklik yok

Resolver mantığı değişmedi — yalnızca dosya konumu taşındı. Üretilen `_active.css` çıktısı aynıdır.

---

### Tema dizin yapısı düzleştirildi — BREAKING

`themes/` ara dizini hem CSS hem JS tema ağacından kaldırıldı. Bu yollara doğrudan referans veren consumer app'lerin manuel geçiş yapması gerekir.

| Eskisi | Yenisi |
|---|---|
| `resources/css/theme/themes/main/` | `resources/css/theme/main/` |
| `resources/css/theme/themes/custom/` | `resources/css/theme/custom/` |
| `resources/js/theme/themes/` | kaldırıldı — override artık `resources/js/theme/custom/preset.ts` |

Boş placeholder klasörler artık gönderilmiyor.

#### Geçiş adımları

1. `php artisan sk:update` çalıştırın — yeni `main/` ağacını `resources/css/theme/main/` altına getirir.

2. Eski klasörleri **MANUEL silin** — `sk:update` bunları otomatik silmez:

   ```bash
   rm -rf resources/css/theme/themes/
   rm -rf resources/js/theme/themes/
   ```

3. Temayı ve asset'leri yeniden derleyin:

   ```bash
   npm run theme:build && npm run build
   ```

#### `VITE_SK_THEME=custom` kullananlar

Override dosyalarınızı yeni konumlara taşıyın:

| Eskisi | Yenisi |
|---|---|
| `resources/css/theme/themes/custom/` | `resources/css/theme/custom/` |
| `resources/js/theme/themes/custom/preset.ts` | `resources/js/theme/custom/preset.ts` |

Taşıma sonrasında `npm run theme:build && npm run build` ile doğrulayın.

#### Varsayılan tema — görsel değişiklik yok

Varsayılan `VITE_SK_THEME=main` (veya değişken tanımlı değil) kullanan projeler için görsel değişiklik yoktur. Geçiş sonrası üretilen `_active.css` çıktısı aynıdır.

---

### Backend vendor taşıması

Geriye dönük uyum iki yolla garanti edilir:

- **Tam taşınan PHP sınıfları** (stub bırakılmayanlar) `StarterKitServiceProvider`'ın kaydettiği `class_alias()` ile çözülür — ilgili `app/…` dosyasını silmediğiniz sürece eski `App\…` import'larınız çalışmaya devam eder. Özelleştirdiğiniz bir kopyayı koruduğunuzda guard *kenara çekilir*, böylece sizin sürümünüz kazanmayı sürdürür.
- **Alias'lı PHP sınıfları** (scaffold'da ince bir `App\…` alt sınıfı/trait'i kalanlar) tanıdık `App\…` import'unu geçerli tutar; gerçek uygulama vendor'dan çalışır.

#### Vendor'a ne taşındı

##### Backend (PHP) — tam taşıma (stub yok; `class_alias` ile çözülür)

| Eskiden (`App\`) | Şimdi (vendor) |
|---|---|
| `App\Support\HtmlSanitizer` | `Lvntr\StarterKit\Support\HtmlSanitizer` |
| `App\Support\TranslatableQueryHelpers` | `Lvntr\StarterKit\Support\TranslatableQueryHelpers` |
| `App\Support\MediaPathGenerator` | `Lvntr\StarterKit\Support\MediaPathGenerator` |
| `App\Support\Scramble\ApiResponseExtension` | `Lvntr\StarterKit\Support\Scramble\ApiResponseExtension` |
| `App\Http\Middleware\AssignTraceId` | `Lvntr\StarterKit\Http\Middleware\AssignTraceId` |
| `App\Http\Middleware\SetLocale` | `Lvntr\StarterKit\Http\Middleware\SetLocale` |
| `App\Http\Middleware\ValidateTurnstile` | `Lvntr\StarterKit\Http\Middleware\ValidateTurnstile` |

`AssignTraceId`, `SetLocale` ve `ValidateTurnstile`, `bootstrap/app.php`'nizden zaten çağrılan `Lvntr\StarterKit\Bootstrap::middleware()` tarafından bağlanır; bu yüzden bootstrap değişikliği gerekmez. Yalnızca `HandleInertiaRequests` scaffold'da kalır — app'e özgü Inertia paylaşılan verisini taşır.

##### Backend (PHP) — vendor + ince `App\` shim (import yolu değişmez)

| Sınıf | Not |
|---|---|
| `App\Http\Responses\DatatableQueryBuilder` | vendor builder'ın ince alt sınıfı |
| `App\Rules\HttpsOrLocalhostUrl` | ince alt sınıf |
| `App\Rules\TurnstileRule` | ince alt sınıf |

##### Backend (PHP) — trait'ler (doğrudan vendor import, alias yok)

PHP trait'leri `class_alias()` ile çözülemez, bu yüzden trait'ler sınıfların aldığı şeffaf `App\…` fallback'ini almaz. Kit'in trait'leri doğrudan vendor namespace'inden import edilir:

| Trait | Import |
|---|---|
| `HasTranslatableRules` | `use Lvntr\StarterKit\Support\HasTranslatableRules;` |
| `HasActivityLogging` (v13.5.0'dan beri) | `use Lvntr\StarterKit\Traits\HasActivityLogging;` |
| `HasMediaCollections` (v13.5.0'dan beri) | `use Lvntr\StarterKit\Traits\HasMediaCollections;` |

Gönderilen model/request scaffold'u bunları zaten `Lvntr\StarterKit\…`'ten import eder. **Projenizde bu trait'lerden birinin yerel kopyası hâlâ varsa (örn. eski sürümden kalma `app/Support/HasTranslatableRules.php`) ve onu silerseniz, önce `App\…` trait'ine referans veren her `use` ifadesini vendor namespace'ine çevirmelisiniz** — geri düşülecek bir alias yoktur.

### Üçüncü-parti config'ler

##### Üçüncü-parti config — artık publish edilmiyor

`config/activitylog.php`, `config/inertia.php` ve `config/media-library.php` artık app'inize kopyalanmaz. Kit yalnızca ihtiyaç duyduğu override'ları `StarterKitServiceProvider::applyVendorConfigDefaults()` ile runtime'da uygular:

- `media-library.path_generator` → vendor `MediaPathGenerator`, ve `media-library.media_model` → `App\Models\Media` (model mevcutsa) — **File Manager Çöp Kutusu / soft-delete için zorunlu**.
- `activitylog.include_soft_deleted_subjects` → `true`
- `inertia.ssr.enabled` → `env('INERTIA_SSR_ENABLED', false)`

Her override, o config'in **kendi kopyanızı publish ettiyseniz atlanır** — publish, tam kontrol için kaçış yolu olarak kalır. İlgili paketin kendi publish tag'ini kullanın, örn. `php artisan vendor:publish --tag=medialibrary-config`.

> Installer'ın daha önce publish edilmiş `config/media-library.php` içine `App\Support\MediaPathGenerator`'ı AST ile enjekte eden davranışı kaldırıldı; path generator artık runtime'da set ediliyor.

### Frontend composable'ları

##### Frontend

- 15 composable (`useApi`, `useCan`, … `useUrlTab`) vendor'dan çalışır; `@/composables/<name>` önce local sonra vendor çözülür. `useAdminMenu.ts` ve `index.ts` düzenlenebilir stub olarak kalır.
- `TurnstileWidget.vue` artık `@lvntr/components/ui/TurnstileWidget.vue` üzerinden gelir.

#### Ne değişmez

| Alan | Durum |
|---|---|
| Taşınan sınıfların mevcut `app/…` kopyaları | Korunur, silinmez |
| Kodunuzdaki `App\…` **sınıf** import'ları | Çalışmaya devam eder (tam taşınanlar için `class_alias`; `DatatableQueryBuilder` / Rule'lar için ince shim) |
| `App\…` **trait** import'ları (`HasTranslatableRules`, `HasActivityLogging`, `HasMediaCollections`) | Alias yok — vendor namespace'ini kullanın (yukarıdaki trait notuna bakın) |
| Daha önce publish ettiğiniz `config/activitylog.php` / `inertia.php` / `media-library.php` | Korunur; sizin dosyanız kazanır (runtime override atlanır) |
| `@/composables/<name>` import yolları | Değişmez |
| Route adları, permission anahtarları, API response zarfı | Değişmez |
| Migration geçmişi | "Nothing to migrate" |

#### İsteğe bağlı temizlik

Bu adımlar tamamen isteğe bağlıdır ve sonra da yapılabilir.

**Tam taşınan PHP sınıfları** — hiç özelleştirmediyseniz, yetim kalan dosyaları silin ki vendor sürümleri (`class_alias` ile) devralsın:

```bash
rm -f app/Support/HtmlSanitizer.php \
      app/Support/TranslatableQueryHelpers.php \
      app/Support/MediaPathGenerator.php \
      app/Support/Scramble/ApiResponseExtension.php \
      app/Http/Middleware/AssignTraceId.php \
      app/Http/Middleware/SetLocale.php \
      app/Http/Middleware/ValidateTurnstile.php
```

> Daha önce publish edilmiş `config/media-library.php` içinde `path_generator` değeri `App\Support\MediaPathGenerator` ise alias ile çalışmaya devam eder. Tamamen runtime varsayılanına geçmek için `config/media-library.php`'yi silin — kit o zaman vendor path generator'ı ve `App\Models\Media`'yı zorunlu kılar.

**Shim'li PHP sınıfları** (`DatatableQueryBuilder`, `HttpsOrLocalhostUrl`, `TurnstileRule`) — ince `App\` alt sınıfını yerinde bırakın; desteklenen override noktası budur. Yalnızca import'larınızı doğrudan `Lvntr\StarterKit\…`'e geçirirseniz silin.

**Trait'ler** (`HasTranslatableRules`, `HasActivityLogging`, `HasMediaCollections`) — yerel kopyanız varsa, önce her `use` ifadesini vendor namespace'ine çevirin, *sonra* yerel dosyayı silin. Import güncellemesini atlamak sınıfı kırar; çünkü trait'lerin `class_alias` fallback'i yoktur.

**Taşınan bir sınıfı özelleştirdiyseniz**, `app/…` dosyanızı koruyun: `class_alias` guard'ı (tam taşınan sınıflar için) onu algılar ve kenara çekilir, böylece sizin sürümünüz kazanmayı sürdürür. Shim'li sınıflar için shim'in kendisini özelleştirin.

#### sk:update çıktısı

`sk:update`, app'inizde hâlâ duran taşınmış dosyaları raporlar (yalnızca bilgilendirme — asla otomatik silinmez):

```
v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Http/Middleware/AssignTraceId.php
  • app/Http/Middleware/SetLocale.php
  • app/Http/Middleware/ValidateTurnstile.php
  • app/Support/HtmlSanitizer.php
  • app/Support/TranslatableQueryHelpers.php
  • app/Support/MediaPathGenerator.php
  • app/Support/HasTranslatableRules.php
  • app/Support/Scramble/ApiResponseExtension.php
  • config/media-library.php
  • config/activitylog.php
  • config/inertia.php
```

(v13.5.0 vendor-resident dosyaları — `app/Domain/FileManager/`, `app/Domain/Shared/` vb. — hâlâ duruyorsa listede ayrıca görünür.)

#### Yeni kurulum (v13.6.0+)

Sıfır bir `sk:install` artık bu yardımcı sınıfları, middleware'leri, trait'leri veya üç üçüncü-parti config'i `app/` / `config/`'e kopyalamaz. Bunlar `vendor/lvntr/laravel-starter-kit/src/` ile kit'in runtime config override'larından çalışır. Scaffold, üretilen domain kodunun tanıdık import'larını koruması için `DatatableQueryBuilder` ve iki validation Rule için ince `App\` shim'lerini hâlâ gönderir; trait yardımcıları (`HasTranslatableRules`) doğrudan vendor namespace'inden import edilir.

---

### Sayfa-geçiş yükleme overlay'i (opt-in)

Bu sürüm `SkPageLoader`'ı ships eder — `@lvntr/components/ui/SkPageLoader.vue` konumunda animasyonlu tam-ekran sayfa-geçiş loader'ı; `usePageLoading` composable'ı (anti-flicker gecikmeli Inertia router olayları) tarafından sürülür ve `theme/main/components/page-loader.css` ile temalanır. Hem CSS slot'u hem composable `sk:update` ile gelir; CSS üretilen `_active.css`'e zaten import edilir. Marka sözcüğü harf-harf animasyon yapar ve aktif accent + temayı izler (bkz. [theme.tr.md](./theme.tr.md) — Accent renk sistemi).

**Opt-in'dir — gelen scaffold onu mount etmez.** v13.6.0 scaffold'undaki hiçbir layout `<SkPageLoader/>` render etmez; siz wire edene kadar loader uykuda kalır (CSS'i de etkisiz). Upgrade'de otomatik davranış değişikliği yoktur.

Açmak için bileşeni `AdminLayout.vue`'nun `overlays` slot'una, diğer global overlay'lerin yanına ekleyin:

```vue
import SkPageLoader from '@lvntr/components/ui/SkPageLoader.vue';

<template #overlays>
    <ConfirmDialogComponent />
    <ToastComponent />
    <AppDialog />
    <ImageLightbox />
    <SkPageLoader :delay="250" />
</template>
```

Loader animasyonlu sözcüğü için `sk-layout.loading` çeviri anahtarını okur ve `prefers-reduced-motion`'ı dikkate alır. Kapatmak için satırı kaldırın.

---

### Tema / CSS / layout reorganizasyonu

Admin panel CSS'i ve layout kabuğu yeniden düzenlendi. Görsel çıktı **değişmez** — varsayılan build (`VITE_SK_THEME=main`) v13.5.11 ile byte-identical'dır. Tüm layout ve bileşen class adları, token değerleri ve DOM yapısı korunur. Değişen yalnızca dosya düzenidir: monolitik bir `_admin.scss` ve dağınık `_*.scss` partial'larındaki stiller artık yapılandırılmış bir `themes/main/` dizin ağacında yaşar; layout kabuğu ise yeniden kullanılabilir bir `AppShell.vue` + ince bir `AdminLayout.vue` kompozisyonuna bölünür.

Yeni opt-in **tema-override sistemi** (`themes/custom/`), herhangi bir CSS slot'unu build zamanında base temaya veya Vue bileşenlerine dokunmadan değiştirmenizi sağlar.

#### Taşınan dosyalar (CSS)

| Kaldırılan | Yerine gelen |
|---|---|
| `resources/css/theme/_admin.scss` | `themes/main/layout/{shell,sidebar,header,page-header,footer}.css` |
| `resources/css/theme/_datatable.scss` | `themes/main/components/datatable.css` |
| `resources/css/theme/_formbuilder.scss` | `themes/main/components/formbuilder.css` |
| `resources/css/theme/_dialog.scss` | `themes/main/components/dialog.css` |
| `resources/css/theme/_toast.scss` | `themes/main/components/toast.css` |
| `resources/css/theme/_tag.scss` | `themes/main/components/tag.css` |
| `resources/css/theme/_card.scss` | `themes/main/components/card.css` |
| `resources/css/theme/_editor.scss` | `themes/main/components/editor.css` |
| `resources/css/theme/_tabs.scss` | `themes/main/components/tabs.css` |
| `resources/css/theme/_menus.scss` | `themes/main/components/menus.css` |
| `resources/css/theme/_navigation.scss` | `themes/main/components/navigation.css` |
| `resources/css/theme/_confirm.scss` | `themes/main/components/confirm.css` |
| `resources/css/theme/_primevue.scss` | `themes/main/components/primevue.css` |
| `_base.scss`'teki `:root` / `.dark` blokları | `themes/main/tokens.css` |
| `theme.css` (açık slot import'ları) | `theme.css` (tek `@import './_active.css'`) |

#### Taşınan dosyalar (layout)

`resources/js/layouts/AdminLayout.vue` artık yeni `resources/js/layouts/AppShell.vue` etrafında ince bir kompozisyondur. Dış prop/slot kontratı (`title`, `subtitle`, `backUrl`, `default`, `page-actions`) **birebir aynıdır** — hiçbir sayfanın import'unu veya template'ini değiştirmesi gerekmez.

#### Yeni dosyalar

| Dosya | Amaç |
|---|---|
| `resources/js/layouts/AppShell.vue` | Yeniden kullanılabilir yapısal kabuk (sidebar durumu, responsive margin'ler, adlandırılmış bölgeler) |
| `resources/css/theme/themes/main/` | Dahili ana tema (tüm CSS slot'ları için kaynak) |
| `resources/css/theme/themes/custom/` | Boş CSS override tema iskeleti (bkz. o dizindeki `themes/custom/README.md`) |
| `scripts/sk-theme-build.mjs` | CSS tema resolver'ı — `_active.css`'i üretir; `dev` ve `build` tarafından açıkça çağrılır |
| `resources/js/theme/themes/custom/` | Boş PrimeVue preset override iskeleti — `.gitkeep` ve override reçetesini açıklayan `README.md` ile birlikte gelir |
| `scripts/vite-plugin-sk-theme.mjs` | Vite plugin'i — Vite'ın lifecycle'ı içinde `_active.css`'i üretir ve `@/theme/preset` import'unu build zamanında aktif temanın preset'ine çözümler |

#### Üretilen artefakt

`resources/css/theme/_active.css`, `scripts/sk-theme-build.mjs` tarafından üretilir:

- `.gitignore`'da listelenmiştir — asla commit edilmez.
- Her `npm run dev` ve `npm run build`'de yeniden üretilir — resolver her iki script'e açıkça zincirlenir (npm lifecycle hook kullanılmaz; bu nedenle `ignore-scripts=true` altında da doğru çalışır).
- `sk:update` tarafından hash-takip edilmez.

#### `.env.example` — yeni anahtar

```dotenv
VITE_SK_THEME=main
```

Henüz yoksa bu satırı `.env` ve `.env.example` dosyalarınıza ekleyin. Varsayılan `main`'dir; değişkeni atlamak aynı etkiyi verir.

#### `package.json` — resolver `dev` ve `build`'e zincirlendi

Resolver'ın açıkça zincirin bir parçası olarak çalışması için `dev`, `build` ve `theme:build` script'leri güncellendi:

```json
"theme:build": "node scripts/sk-theme-build.mjs",
"dev": "node scripts/sk-theme-build.mjs && vite",
"build": "node scripts/sk-theme-build.mjs && vite build && vite build --ssr",
```

Kendi `package.json`'ınızı yönetiyorsanız `dev` ve `build`'i bu kalıba göre güncelleyin. Resolver açık bir `&&` adımı olmalıdır — **`predev` / `prebuild` lifecycle hook'larını kullanmayın**; npm, `ignore-scripts=true` ayarı aktifken bu hook'ları sessizce atlar (consumer projelerinde ve CI'da yaygın bir güvenlik ayarı), bu da `_active.css`'in oluşturulmamasına ve build'in başarısız olmasına neden olur. `npm run theme:build` ise tam build yapmadan dosyayı isteğe bağlı oluşturmak için kullanılabilir.

#### `_admin.scss` veya herhangi bir `_*.scss` partial'ını özelleştirdiyseniz

1. `php artisan sk:update` çalıştırın — taşınan dosyalar için hash farkı raporlanır.
2. Özelleştirmelerinizi yukarıdaki tabloya göre ilgili `themes/main/` dosyasına kopyalayın.
3. Değişiklikleriniz kapsamlıysa `themes/custom/`'a almayı düşünün (bkz. `docs/theme.tr.md` — özel tema reçetesi).
4. Doğrulamak için `npm run build` çalıştırın.

#### `AdminLayout.vue`'yu özelleştirdiyseniz

`sk:update` hash farkı raporlar. Yeni dosya `AppShell` etrafında ince bir kompozisyondur. Özelleştirmelerinizi yeni sürüme uygulayın — dış kontrat (prop'lar, slot'lar) değişmediğinden sayfa düzeyindeki template'lerin düzenlenmesi gerekmez.

#### PrimeVue preset — geçiş adımı gerekmez

PrimeVue preset resolver tamamen eklemeli ve geriye dönük uyumludur:

- `resources/js/theme/preset.ts` **yerinde kalır** — kit onu asla taşımaz.
- `app.ts`, `@/theme/preset`'i değişiklik olmadan import etmeye devam eder.
- `VITE_SK_THEME=main` (veya değişken tanımlı değilse), build `@/theme/preset`'i taban `preset.ts`'e çözümler — önceki sürümle byte-identical davranış.
- `themes/custom/preset.ts` override'ı yalnızca `VITE_SK_THEME=custom` **ve** dosyayı oluşturduğunuzda devreye girer. Dosya yoksa tabana düşer.

`preset.ts`'i özelleştirmiş mevcut consumer'lar özelleştirilmiş dosyalarını kullanmaya devam eder. Resolver buna müdahale etmez. Custom temaya kendi PrimeVue paletini vermek için bkz. `docs/theme.tr.md` — PrimeVue preset katmanı.

#### Görsel değişiklik yok

Bu yeniden düzenleme yalnızca yapısaldır. `VITE_SK_THEME=main` (varsayılan) ile üretilen `_active.css`, önceki `theme.css` manifestiyle birebir aynı CSS kurallarını aynı sırayla import eder. Token değerleri (aydınlık ve karanlık), class adları ve DOM yapısı aynıdır.

Daha önce resolver'ın dışında sabit import olan dört CSS dosyası (`fonts.css`, `_base.scss`, `_auth.scss`, `utilities.css`) artık `themes/main/` altında yaşıyor ve resolver tarafından normal slot'lar gibi emit ediliyor. Cascade sırası değişmedi. **Varsayılan build v13.5.11 ile byte-identical'dır.**

Geçiş adımı gerekmez. Manuel adımlar yalnızca taşınan dosyaları elle düzenlediyseniz gereklidir.

#### Taşınan dosyalar

| Kaldırılan | Yerine gelen |
|---|---|
| `resources/css/theme/fonts.css` | `themes/main/fonts.css` |
| `resources/css/theme/_base.scss` | `themes/main/_base.scss` |
| `resources/css/theme/_auth.scss` | `themes/main/_auth.scss` |
| `resources/css/theme/utilities.css` | `themes/main/utilities.css` |

#### Değişen dosyalar (import temizliği)

| Dosya | Değişiklik |
|---|---|
| `theme/theme.css` | Sabit `_auth.scss` import'u kaldırıldı — resolver emit ediyor. Artık yalnızca `@import './_active.css'` içeriyor. |
| `app.css` | Sabit `utilities.css` tail import'u kaldırıldı — resolver son slot olarak emit ediyor. |

#### `fonts.css`, `_base.scss`, `_auth.scss` veya `utilities.css`'i özelleştirdiyseniz

1. `php artisan sk:update` çalıştırın — taşınan dosyalar için hash farkı raporlanır.
2. Özelleştirmelerinizi yukarıdaki tabloya göre ilgili `themes/main/` dosyasına kopyalayın.
3. Değişiklikleriniz temaya özgüyse `themes/custom/` altına almayı düşünün (örn. `themes/custom/fonts.css`). Bkz. `docs/theme.tr.md` — Tam slot referansı.
4. Doğrulamak için `npm run build` çalıştırın.

#### Öksüz kalan dosyalar (silinebilir)

`sk:update` yeni `themes/main/` dosyalarını ekler ama diskte zaten bulunan eski düz-yol kopyalarını silmez. Yükseltmeden sonra şu dört dosya artık hiçbir yerden import edilmez ve ağacı temiz tutmak için silinebilir — yerinde bırakmak zararsızdır (hiçbir şey import etmez):

- `resources/css/theme/fonts.css`
- `resources/css/theme/_base.scss`
- `resources/css/theme/_auth.scss`
- `resources/css/theme/utilities.css`

---

### Permission direktif plugin'i → vendor

`v-can` / `v-role` izin direktif plugin'i (`resources/js/plugins/permission.ts`) artık varsayılan olarak vendor paketinden çözülüyor; kit composable'larının çalışma şeklini aynalıyor. `app.ts`'teki `@/plugins/permission` import'u değişmedi; yerel dosya yoksa vendor kopyasına düşer. **Davranış değişikliği yok** — direktifler aynı.

Geçiş gerekmez. Mevcut projeler yerel `resources/js/plugins/permission.ts`'lerini korur; bu kopya vendor sürümünü gölgelemeye devam eder, yani hiçbir şey kırılmaz.

#### Ne değişti

| Dosya | Değişiklik |
|---|---|
| `resources/js/plugins/permission.ts` | Artık vendor'dan sağlanıyor. Ölü `useCan()` export'u kaldırıldı (`@/composables/useCan` kullanın); yalnızca `PermissionPlugin` (`v-can` / `v-role`) shipping. |
| `vite.config.ts` | Yeni `@/plugins/*` resolver'ı — önce yerel kopya, sonra vendor fallback — `@/composables/*`'ı aynalar. |
| `tsconfig.json` | Yeni `@/plugins/*` path eşlemesi. |

#### Yerel kopyanız artık isteğe bağlı

`sk:update` `resources/js/plugins/permission.ts`'i artık stub olarak göndermez, ama projenizdeki mevcut kopyayı da **silmez**. O yerel kopya çalışmaya devam eder — vendor sürümünü gölgeler. Şunları yapabilirsiniz:

- Vendor yönetimli sürümü almak için **silin** (hiç düzenlemediyseniz önerilir): `rm resources/js/plugins/permission.ts`.
- Kendi kopyanıza sabitlenmek veya direktifleri özelleştirmek için **tutun**.

Sonradan düzenlenebilir bir kopya oluşturmak için `php artisan sk:publish --tag=plugins` çalıştırın — vendor sürümünü tekrar gölgeler.

#### Davranış değişikliği yok

Direktifler aynı; yalnızca çözümleri taşındı. `v-can` / `v-role` tıpkı önceki gibi davranır ve `app.ts`'in düzenlenmesi gerekmez.

---

### Güvenlik Ayarları yeniden tasarımı — parola politikası uygulaması

Bu sürüm **Ayarlar → Güvenlik** sekmesini yeniden tasarlar ve parola kuralları ile parola geçerlilik süresinin sunucu tarafında tam olarak uygulanmasını sağlar.

#### Yeni migration — `users.password_changed_at`

`users` tablosuna nullable bir `timestamp` kolonu eklenir. Migration sırasında mevcut satırlar `now()` ile geri dolduğundan, deploy sonrasında hiçbir kullanıcı aniden süresi dolmuş kabul edilmez.

```bash
php artisan migrate
```

#### Yeni `auth.*` ayar anahtarları

`auth` grubuna altı yeni anahtar eklenir. Tümünün geri uyumlu fallback'leri vardır; dolayısıyla mevcut kurulumlar seeder çalıştırmadan yükseltme yaptığında etkilenmez.

| Anahtar | Runtime fallback (anahtar DB'de yokken) | Seeder (yeni kurulum) |
|---|---|---|
| `auth.login_throttle` | `'1'` (throttle zaten aktif) | `'1'` |
| `auth.password_min_length` | `10` | `'10'` |
| `auth.password_expiry_days` | `0` (sınırsız) | `'0'` |
| `auth.password_require_mixed_case` | `'1'` | `'1'` |
| `auth.password_require_numbers` | `'1'` | `'1'` |
| `auth.password_require_symbols` | `'1'` | `'1'` |

**Mevcut kurulumlar:** `sk:update`, güncellenmiş `_03_SettingSeeder.php` dosyasını teslim eder. Seeding isteğe bağlıdır; seeding çalıştırılmadan yukarıdaki runtime fallback'ler kullanılır — davranış değişmez (fallback'ler özellik öncesi sertleştirilmiş baseline'a eşittir).

**Yeni kurulumlar:** önerilen default'ları uygulamak için seeder'ı `sk:install` kapsamında çalıştırın:

```bash
php artisan db:seed --class=_03_SettingSeeder
```

#### Giriş denemesi limiti toggle'ı

`auth.login_throttle = '0'` runtime'da Fortify giriş rate limiter'ını devre dışı bırakır. Default değer `'1'`'dir (throttle aktif). Throttle'ı kapatmak bilinçli bir güvenlik düşürümüdür; bu ayar yalnızca yöneticilere açıktır.

#### Parola politikası uygulaması

Parola politikası ayarları yapılandırıldığında `PasswordValidationRules` trait, bunları her yeni parolaya uygular — kayıt, parola sıfırlama, parola onayı ve profil güncellemesi. Kurallar yalnızca yeni gönderilen parolalara uygulanır; mevcut saklı parolalar geçersiz olmaz.

| Ayar | Aktifken etkisi |
|---|---|
| `password_min_length` | `Password::min(n)` uygular |
| `password_require_mixed_case` | `->mixedCase()` uygular |
| `password_require_numbers` | `->numbers()` uygular |
| `password_require_symbols` | `->symbols()` uygular |

Politika yapılandırılmadığında (tüm fallback'ler) davranış, önceki `Password::default()` kurulumuna eşdeğerdir.

#### Parola geçerlilik süresi middleware'i (`EnsurePasswordNotExpired`)

`auth.password_expiry_days > 0` olduğunda, `password_changed_at` değeri yapılandırılan gün sayısından daha eski olan kimlik doğrulanmış kullanıcılar, parolalarını güncelleyene kadar adanmış, guest tarzı bir parola-süresi-doldu ekranına (`Auth/PasswordExpired.vue`, `password.expired` rotası) yönlendirilir. Ekran giriş / parola sıfırlama layout'unu yansıtır — sidebar veya panel çerçevesi yoktur — ve parola tekrar güncel olduğunda kullanıcıyı dashboard'a geri gönderir.

Muaf rotalar (redirect döngüsü oluşamaz):

- parola-süresi-doldu sayfası (`password.expired`, redirect hedefi)
- çıkış
- iki faktör challenge
- Fortify parola uç noktaları

`password_changed_at = null` muaf tutulur (migration geri doldurmadan sonra pratikte oluşmaz).

Middleware, stub'ın `routes/web.php` dosyasındaki kimlik doğrulamalı panel route grubu aracılığıyla `web + auth` middleware grubuna kaydedilir. `routes/web.php` dosyasını özelleştirdiyseniz (yani `sk:update` bu dosyaya dokunmuyorsa), `EnsurePasswordNotExpired` middleware'ini auth grubuna manuel olarak eklemeniz gerekir:

```php
use App\Http\Middleware\EnsurePasswordNotExpired;

Route::middleware(['auth', 'verified', EnsurePasswordNotExpired::class])->group(function () {
    // kimlik doğrulamalı rotalarınız
});
```

#### SecurityTab özelleştirmesi

`resources/js/pages/Admin/Settings/components/SecurityTab.vue` dosyasını özelleştirdiyseniz, `sk:update --dry-run` çalıştırarak diff'i görün ve değişikliklerinizi yeni üç alt sekme yapısıyla birleştirin. Güncelleme hash uyuşmazlığı olarak işaretlenecektir — manuel olarak uygulayın.

#### Değişmeyen alanlar

| Alan | Durum |
|---|---|
| Mevcut `auth.*` ayar anahtarları (`registration`, `email_verification`, `password_reset`, `two_factor`) | Değişmez |
| `UpdateAuthSettingsRequest` — eski dört alanlı POST | Kabul edilmeye devam eder; yeni alanlar `sometimes` |
| Giriş throttle default'u | Aktif kalmaya devam eder (`'1'`) — yükseltme sonrası davranış değişmez |
| Mevcut kullanıcıların parolaları | Politika değişikliğiyle geçersiz olmaz |
| API yanıt zarfı | Değişmez |

---

## v13.5.0 → v13.5.3

### Özet

Bu sürümde `sk:doctor` / System Health paneli, File Manager için İmzalı Paylaşım Bağlantıları (Signed Share Link), Bulk Action API sertleştirmesi ve API İstemci UI eklendi. Yeni migration, config key ve permission adımları zorunludur.

### Yükseltme Adımları

**1. Paketi güncelleyin:**

```bash
composer update lvntr/laravel-starter-kit
```

**2. Yeni migration'ları yayınlayın ve çalıştırın:**

```bash
php artisan vendor:publish --tag=starter-kit-migrations
php artisan migrate
```

Yeni migration: `file_manager_share_revocations` tablosu (İmzalı Paylaşım Bağlantısı iptali için zorunlu).

**3. File Manager config'ini güncelleyin (yeni `share.*` key'leri):**

```bash
php artisan vendor:publish --tag=starter-kit-config --force
```

`config/file-manager.php` dosyasına şu key'ler eklenir: `share.enabled`, `share.default_ttl_hours`, `share.max_ttl_hours`, `share.allow_revoke`. Mevcut key'ler etkilenmez.

**4. Yeni stub'ları yayınlayın (DİKKAT: özelleştirilmiş stub'lar override edilir, önce diff alın):**

```bash
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**5. Yeni izinleri seed'leyin ve cache'i temizleyin:**

```bash
php artisan db:seed --class=PermissionResourcesSeeder
php artisan permission:cache-reset
```

Yeni izinler: `system.health.view`, `share-media`, `revoke-share-media`, `api-clients.create`, `api-clients.read`, `api-clients.update`, `api-clients.delete`, `api-tokens.create`, `api-tokens.read`, `api-tokens.delete`

### Davranış Değişiklikleri

- **Passport istemci UI** — `confidential=false` olan authorization-code client'ları artık UI üzerinden oluşturulamaz. Mevcut DB kayıtları etkilenmez.
- **Personal Access Token mint** — `user_id` body alanı kaldırıldı. Başkası adına PAT oluşturmak için artisan komutunu kullanın.
- **`AppServiceProvider` stub** — varsa duplicate Passport scope / `Gate::before` bloğunu kaldırın; `StarterKitServiceProvider` bunları kaydetmeye devam eder.
- **`BulkActionRequest`** — ID'ler artık `string|min:1|max:64` kuralıyla doğrulanıyor. Mevcut integer-only bulk action'lar etkilenmez.

---

## v13.4.x → v13.5.0

### Özet

Bu sürümde paket runtime vendor'a taşındı. `app/` dizinindeki mevcut dosyalarınız **değişmez**; olduğu gibi çalışmaya devam eder. `composer update` tek zorunlu adımdır.

### Yükseltme Adımları

```bash
composer update lvntr/laravel-starter-kit
php artisan migrate
```

`php artisan migrate` komutu "Nothing to migrate" döner çünkü mevcut migration history'niz bu sürümün vendor migration dosyalarıyla birebir eşleşiyor.

#### Opsiyonel adımlar

```bash
# Wayfinder typed route dosyalarını yenile (diff beklenmez)
php artisan wayfinder:generate

# Stub güncellemelerini kontrol et (hash değişmişse bildirir, zorlamaz)
php artisan sk:update --dry-run
```

### Ne Değişmez

| Alan | Durum |
|------|-------|
| `app/Domain/FileManager/` dosyaları | Korunur, silinmez |
| `app/Domain/Shared/` dosyaları | Korunur, silinmez |
| `app/Traits/HasActivityLogging.php` | Korunur |
| `app/Traits/HasMediaCollections.php` | Korunur |
| `app/Helpers/sk-helpers.php` | Korunur, fonksiyonlarınız baskın kalır |
| `app/Http/Responses/ApiResponse.php` | Korunur |
| `app/Http/Middleware/CheckResourcePermission.php` | Korunur |
| Route isimleri (`file-manager.*`) | 19 route adı AYNEN |
| Migration history | "Nothing to migrate" |
| Config key'leri (`starter-kit.*`, `file-manager.*`) | Mevcut key'ler korundu |
| Frontend `@lvntr` alias | Dokunulmadı |
| Permission key'leri (`files.read`, `files.update`, vb.) | AYNEN |
| API response envelope (`success`, `status`, `message`, `data`) | AYNEN |

### Opsiyonel Cleanup

#### Backend dosyaları (vendor'a taşıma)

`app/Domain/FileManager/`, `app/Domain/Shared/` gibi dosyalar artık vendor'dan da çalışıyor. Eğer bu dosyaları uygulamanızdan kaldırıp vendor versiyonunu kullanmak istiyorsanız adım adım rehber için bakın:

`docs_project/migrate-existing-project-to-vendor.tr.md` (uygulama worktree'sinde)

Bu adım tamamen isteğe bağlıdır ve hemen yapılması gerekmez.

#### Frontend (vendor symlink'e geçiş)

App'te `resources/js/components/Lvntr-Starter-Kit/` klasörü hâlâ duruyorsa ve özel customization yoksa, vendor frontend'ine geçebilirsiniz:

1. **Vite alias** — `vite.config.ts` içinde `@lvntr/components` alias'ını vendor path'e yönlendirin:

   ```ts
   '@lvntr/components': path.resolve(__dirname, 'vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit'),
   ```

   `Components({ dirs })` plugin array'ine vendor path ekleyin:

   ```ts
   dirs: [
     'resources/js/components',
     'vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit',
   ],
   ```

   `preserveSymlinks: true` olduğundan emin olun.

2. **App kopyasını silin**:

   ```bash
   rm -rf resources/js/components/Lvntr-Starter-Kit
   ```

3. **Build smoke**:

   ```bash
   npm run build
   ```

   Exit 0 olmalı.

Customize edilmiş component'iniz varsa silmeyin; kendi `resources/js/components/<X>` altında app-specific bileşenlerinizi tutarken vendor lib'i import edebilirsiniz.

#### sk:sync deprecation

`php artisan sk:sync` deprecated oldu. Composer path repository (symlink) workflow'u kullananlar için gerekmiyordu zaten. `--force` ile eski davranış korunur ama önerilmez.

### sk:update Çıktısı

Bu sürümden itibaren `sk:update`, vendor'a taşınan runtime dosyalar için kopyalama yapmaz. Çıktıda şuna benzer bir bilgi mesajı görürsünüz:

```
[INFO] v13.5.0+: Aşağıdaki dosyalar artık vendor'da çalışıyor.
       Silmek opsiyonel:
         app/Domain/FileManager/
         app/Domain/Shared/{Actions,Contracts,DTOs,Pipelines}
         app/Traits/HasActivityLogging.php
         app/Traits/HasMediaCollections.php
         app/Helpers/sk-helpers.php
         app/Http/Responses/ApiResponse.php
         app/Http/Middleware/CheckResourcePermission.php
         app/Http/Middleware/SecurityHeaders.php
         app/Exceptions/ApiException.php
         app/Exceptions/ApiExceptionHandler.php
         app/Http/Controllers/FileManagerController.php
         app/Http/Requests/FileManager/
         app/Console/Commands/PurgeFileManagerTrash.php
```

Hash takipli stub'lar (auth/layout/user/rol/ayar/config) için mevcut diff/bildirim davranışı korundu.

### Yeni Install (v13.5.0+)

Yeni bir projede `php artisan sk:install` koştuğunuzda `app/Domain/FileManager/`, `app/Domain/Shared/`, `app/Traits/`, `app/Helpers/sk-helpers.php`, `app/Http/Responses/ApiResponse.php`, `app/Http/Middleware/CheckResourcePermission.php` dosyaları artık `app/` dizinine kopyalanmıyor. Bu modüller doğrudan `vendor/lvntr/laravel-starter-kit/src/` altından çalışıyor.

Uygulamaya publish edilen dosyalar: auth/layout Vue bileşenleri, User/Role/Setting domain iskeleti, config dosyaları, tek satır route stub'ları.

---

## v13.4.8 → v13.4.9

Bkz. [CHANGELOG.md](../CHANGELOG.md#13490---2026-05-02).

Kısa geçiş:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

---

## v13.4.x → v13.4.10

Bkz. [CHANGELOG.md](../CHANGELOG.md#134100---2026-05-04).

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

---

## 13.4.0 → 13.4.1 — API response sertleştirme + Postman/Apidog sync + OAuth UUID fix

> **Özet:** Bu patch, baştan sona elden geçirilen API response zarfını (trace-id pipeline, merkezi exception handler, leak kapatan controller patch'leri) iki yeni API client entegrasyonu (Postman + Apidog sync) ve iki adet kurulum fix'i (OAuth migration'ları UUID-uyumlu, `site:install` artık Passport personal access client'ı otomatik oluşturuyor) ile birleştiriyor. Çoğu değişiklik **additive** (yeni alan/header, yeni admin butonları) ama **üç adet API-response davranışsal breaking** noktası UI toast metinlerini veya katı client schema'larını etkileyebilir: `abort()` raw mesaj whitelist'i + `ModelNotFoundException` mesaj formatı + `Api/AuthController` ham User → UserResource geçişi.

### 0. Kimi etkiler?

| Kullanıcı | Ne yapmalı |
| --- | --- |
| Paketi yeni kuranlar (`composer create-project` + `sk:install`) | Hiçbir şey — stubs zaten 13.4.1 sürümünde. |
| `sk:update` düzenli çalıştıranlar | `composer update` + `php artisan sk:update`. `ApiResponse`, `ApiExceptionHandler`, `AssignTraceId`, `sk-helpers.php` otomatik taşınır; **controller'lar manuel** (Adım 4). |
| Custom controller'lara sahip olanlar | Adım 4'teki patch'leri elle uygulayın — özellikle `catch (LogicException $e) → throw ApiException::...` pattern dönüşümü. |
| Sadece paket `src/` kullananlar (publish yapmadı) | `composer update lvntr/laravel-starter-kit` yeter; Bootstrap otomatik register ediyor. |
| Kendi `app/Http/Middleware/AssignTraceId.php` yazmış olanlar | Sınıf adı çakışır; paket stub'ını tercih edin veya kendi class'ınızı yeniden adlandırın. |

### 1. Upgrade öncesi hazırlık

1. **Branch + yedek:** `git checkout -b upgrade/v13.4.1 && git push`
2. **Frontend/mobile takım:** API response formatındaki additive alanları (`trace_id` body, `X-Request-ID` header, `X-Correlation-ID` echo, 429 `Retry-After`) onlara haber verin — strict şema kullananlar eklesinler.
3. **QA:** Hata mesajları UI'da toast olarak gösteriliyorsa, **Adım 2'deki davranışsal breaking**'leri kısa bir QA pass'inden geçirin (abort() mesajları, model-not-found mesaj formatı, auth me/login response şekli).
4. **Ortam kontrolü:** `composer test` + `npm run build` mevcut sürümde geçiyor mu?

### 2. Davranışsal breaking değişiklikler

Status kodları değişmedi; zarf alan listesi değişmedi; sadece **`message` metni** ve **auth payload içindeki `data.user` alan listesi** etkilenebilir.

#### 2.1 `abort($code, 'custom message')` artık mesajı dışa sızdırmıyor

```diff
- // Eskiden: body.message = "SQL error: table users missing col xyz"
- abort(400, 'SQL error: table users missing col xyz');
+ // Artık: body.message = "Bad request."  (iç detay düşer)
+ abort(400, 'SQL error: ...');   // Bu mesaj artık client'a gitmez.
```

**Neden:** `HttpExceptionInterface` dalı artık `$e->getMessage()` yerine sabit `defaultMessageForStatus()` kullanıyor (K3). İç mesajlar `APP_DEBUG=true` iken `debug.message` alanına düşer.

**Geçiş yolu:** Client'a mesaj göstermek istediğiniz kontrollü durumlarda:

```php
// Eski
abort(400, 'Invalid coupon code.');

// Yeni (handler'dan geçer, trace_id + correlation headers eklenir)
throw \App\Exceptions\ApiException::badRequest('Invalid coupon code.');
```

#### 2.2 `ModelNotFoundException` mesajı model adını içeriyor

```diff
- body.message: "The requested resource was not found."
+ body.message: "User not found."          // veya Role, Product, …
```

**Neden:** `ApiExceptionHandler::modelNotFoundMessage` artık `class_basename($e->getModel())` ile resolve ediyor (K4 — önceki AGENTS.md vaadini karşılıyor). Güvenlik etkisi yok — model sınıf adı zaten URL'den tahmin edilebilir.

**Geçiş yolu:** Frontend'de message string'ine karşı eşleşme yapan kod varsa regex'i gevşetin (`/(not found|bulunamadı)/i` gibi) veya status kodu (404) üzerinden dallanın.

#### 2.3 `Api/AuthController` ham User → `UserResource`

```diff
  POST /api/v1/auth/login (default kind)
  POST /api/v1/auth/register (no-verification path)
  POST /api/v1/auth/two-factor-challenge
  GET  /api/v1/auth/me

- data.user: {
-     id: 1, first_name: "...", email: "...",
-     status: "active", email_verified_at: "...",
-     two_factor_confirmed_at: null,
-     avatar_url: "...", created_at: "...", updated_at: "..."
- }
+ data.user: <UserResource::toArray() çıktısı, app/Http/Resources/Admin/User/UserResource.php>
```

**Neden:** Ham Eloquent serializasyonu `$hidden`'a güvenmek zorundaydı; gelecekte eklenen hassas bir alan unutulsa sessizce sızardı. `UserResource` artık kontrat — hangi alan client'a gidiyor açıkça yazılı.

**Geçiş yolu:** `UserResource`'un döndürdüğü alan listesini kontrol edin (`app/Http/Resources/Admin/User/UserResource.php`). Ham model'in vardı ama Resource'ta olmayan alana bağımlıysanız, Resource'a ekleyin veya kendi `AuthUserResource` yazıp AuthController'da kullanın.

### 3. Paket güncellemesi

```bash
composer update lvntr/laravel-starter-kit --with-all-dependencies
php artisan sk:update              # Otomatik: ApiResponse + ApiExceptionHandler + sk-helpers + AssignTraceId
npm install                         # Değişmedi ama alışkanlık
```

`sk:update` çıktısı şu dosyaları otomatik günceller:
- `app/Http/Responses/ApiResponse.php`
- `app/Exceptions/ApiExceptionHandler.php`
- `app/Helpers/sk-helpers.php`
- `app/Http/Middleware/AssignTraceId.php` (**yeni** — dosya yoksa oluşturulur)
- `app/Http/Middleware/SecurityHeaders.php` (dokunulmadı ama listede)

> **Önemli:** `AssignTraceId.php` dosyası `sk:update` sonrası mevcut değilse, paket `Bootstrap::middleware()` `App\Http\Middleware\AssignTraceId` sınıfına referans veriyor ve **ilk API request'te ClassNotFoundException atar**. `sk:update` başarılı olduysa sorun yok; emin olmak için: `ls app/Http/Middleware/AssignTraceId.php`.

### 4. Manuel controller patch'leri (publish edilmiş custom'lar için)

`sk:update` controller'ları otomatik güncellemez — birçok projede custom metodlar eklenmiş oluyor. Aşağıdaki 11 leak pattern'ini elle temizleyin. Pattern evrensel:

```diff
- catch (LogicException $e) {
-     return to_api(null, $e->getMessage(), 422);
- }
+ catch (LogicException $e) {
+     throw \App\Exceptions\ApiException::unprocessable($e->getMessage());
+ }
```

**Etkilenen dosyalar:**

| Dosya | Satır / metot |
|---|---|
| `app/Http/Controllers/FileManagerController.php` | `bulkDelete`, `createFolder`, `renameFolder`, `moveItem`, `deleteFolder`, `upload`, `deleteFile` — 7 adet |
| `app/Http/Controllers/Api/UserController.php` | `destroy` — `to_api(null, 'Unauthenticated.', 401)` → `throw ApiException::unauthorized()`; `to_api(null, $e->getMessage(), 400)` → `throw ApiException::badRequest(...)` |
| `app/Http/Controllers/Api/Auth/AuthController.php` | `login` — `to_api(null, 'Invalid email or password.', 401)` → `throw ApiException::unauthorized(...)`; `twoFactorChallenge` — aynısı "Invalid or expired two-factor code." için |

Her controller'ın başına `use App\Exceptions\ApiException;` eklemeyi unutmayın. Son olarak `destroy`'dakine benzer yerlerde `return to_api(status: 204);` `try` bloğunun **dışına** taşınır (Adım 2'deki çıkış akışı değişimi):

```diff
- try {
-     $action->execute($user, (string) $performedById);
-     return to_api(status: 204);
- } catch (\LogicException $e) {
-     return to_api(null, $e->getMessage(), 400);
- }
+ try {
+     $action->execute($user, (string) $performedById);
+ } catch (\LogicException $e) {
+     throw ApiException::badRequest($e->getMessage());
+ }
+
+ return to_api(status: 204);
```

### 5. Api/AuthController UserResource geçişi (publish edilmişse)

Adım 2.3'te anlatılan davranış değişimini uygulamak için `Api/Auth/AuthController.php` patch'i:

```diff
 use App\Domain\Auth\Actions\TwoFactorChallengeAction;
 use App\Domain\Auth\DTOs\LoginDTO;
 use App\Domain\Auth\DTOs\RegisterDTO;
+use App\Exceptions\ApiException;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Api\Auth\LoginRequest;
 use App\Http\Requests\Api\Auth\RegisterRequest;
 use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
+use App\Http\Resources\Admin\User\UserResource;
 use App\Http\Responses\ApiResponse;

 public function register(...): ApiResponse
 {
     $result = $action->execute(...);
+    $userPayload = new UserResource($result['user']->loadMissing('roles'));

     if ($result['requires_verification']) {
         return to_api(
-            ['user' => $result['user'], 'requires_verification' => true],
+            ['user' => $userPayload, 'requires_verification' => true],
             'Registration successful. ...',
             201,
         );
     }

-    return to_api($result, 'Registration successful.', 201);
+    return to_api(
+        ['user' => $userPayload, 'token' => $result['token'], 'requires_verification' => false],
+        'Registration successful.',
+        201,
+    );
 }

 // login default branch
-    default => to_api(
-        ['user' => $result['user'], 'token' => $result['token']],
-        'Login successful.',
-    ),
+    default => to_api(
+        [
+            'user' => new UserResource($result['user']->loadMissing('roles')),
+            'token' => $result['token'],
+        ],
+        'Login successful.',
+    ),

 // me
-    return to_api($request->user());
+    return to_api(new UserResource($request->user()->loadMissing('roles')));

 // twoFactorChallenge
-    return to_api($result, 'Login successful.');
+    return to_api(
+        [
+            'user' => new UserResource($result['user']->loadMissing('roles')),
+            'token' => $result['token'],
+        ],
+        'Login successful.',
+    );
```

### 6. MakeDomainCommand scaffold (publish edilmişse)

`app/Console/Commands/MakeDomainCommand.php` publish edilmişse, yeni scaffold template'i için iki nokta:

```diff
 use {$dtoNamespace}\\{$this->dn}DTO;
+use App\Exceptions\ApiException;
 use App\Http\Controllers\Controller;
 ...

 public function destroy({$this->dn} \${$v}, Delete{$this->dn}Action \$action): ApiResponse|JsonResponse
 {
     try {
         \$action->execute(\${$v});
-
-        return to_api(status: 204);
     } catch (\LogicException \$e) {
-        return to_api(null, \$e->getMessage(), 400);
+        throw ApiException::badRequest(\$e->getMessage());
     }
+
+    return to_api(status: 204);
 }
```

Testiniz `tests/Feature/Console/MakeDomainCommandTest.php`'de yeni scaffold'u doğruluyorsa assertion güncellenmeli:

```diff
 expect(file_get_contents(app_path("Http/Controllers/Api/{$domain}Controller.php")))
-    ->toContain('return to_api(null, $e->getMessage(), 400);');
+    ->toContain('throw ApiException::badRequest($e->getMessage());');
```

### 7. Kurulum zamanı fix'leri (OAuth + Postman ayarları + Passport personal client)

Bu üç adım, **13.4.1 öncesi kurulmuş tüm mevcut install'lar** için geçerli. API response işinden bağımsız çalışır — controller publish etsen de etmesen de `sk:update` sonrası çalıştır.

#### 7.1 OAuth migration'ları UUID-uyumlu hale getirildi

Üç Passport migration'ı artık `foreignUuid` / `nullableUuidMorphs` kullanıyor (önceden `foreignId` / `nullableMorphs`). Bu, starter kit'in `users.id` için gönderdiği `char(36)` primary key ile eşleşiyor. Patch uygulanmazsa Passport ilk access token insert denemesinde `SQLSTATE 1265: Data truncated for column 'user_id'` hatasıyla API login akışını bozar.

Taze kurulumlar bunu `site:install` sırasında otomatik alır. **Mevcut install'lar için** üç migration'ı canlı veri üzerinde yeniden çalıştır:

```bash
# 1. Üç migration'ı rollback et (veri kaybı yok — oauth_* tabloları
#    her token üretimde yeniden doluyor):
php artisan migrate:rollback --path=database/migrations/2026_03_04_205119_create_oauth_auth_codes_table.php
php artisan migrate:rollback --path=database/migrations/2026_03_04_205120_create_oauth_access_tokens_table.php
php artisan migrate:rollback --path=database/migrations/2026_03_04_205122_create_oauth_clients_table.php

# 2. Yeni şema ile yeniden çalıştır:
php artisan migrate
```

Rollback mümkün değilse (schema fork'unda zaten `char(36)` user_id satırları varsa), kolonu manuel olarak düzelt:

```sql
ALTER TABLE oauth_access_tokens MODIFY user_id CHAR(36) NULL;
ALTER TABLE oauth_auth_codes    MODIFY user_id CHAR(36) NOT NULL;
ALTER TABLE oauth_clients       MODIFY owner_id CHAR(36) NULL;
```

Bir login testiyle doğrula — Adım 9 (Regresyon testi).

#### 7.2 Postman / Apidog kimlik bilgileri `.env`'den ayarlar tablosuna taşındı

Postman'i üç `.env` anahtarıyla bağlayan önceki versiyon kaldırıldı. Yapılandırma artık `postman` / `apidog` settings gruplarında duruyor ve `api_key` / `access_token` alanları `config/settings.php → sensitive_keys` aracılığıyla DB'de encrypted tutuluyor.

`.env` içinde `POSTMAN_API_KEY`, `POSTMAN_WORKSPACE_ID` veya `POSTMAN_COLLECTION_ID` varsa bir kerelik olarak ayarlar tablosuna taşı, sonra `.env`'den sil:

```bash
php artisan tinker --execute '
use App\Models\Setting;
Setting::setValue("postman.api_key", env("POSTMAN_API_KEY"));
Setting::setValue("postman.workspace_id", env("POSTMAN_WORKSPACE_ID"));
Setting::setValue("postman.collection_id", env("POSTMAN_COLLECTION_ID"));
echo "migrated";
'
```

Ardından her iki dosyadan (`.env` ve `.env.example`) üç anahtarı da sil. Admin UI'da **Settings → API Clients → Postman** saklanan değerleri gösterir (gizli alanlar maskeli); anahtarı ileride rotate etmek için buradan yönet. Apidog aynı şekilde **Settings → API Clients → Apidog** üzerinden yapılandırılır (Access Token + Project ID).

#### 7.3 Passport personal access client (`site:install` içindeki yeni adım)

`site:install` artık `passport:keys` ile admin-user seed adımları arasında `passport:client --personal --provider=users`'ı otomatik çalıştırıyor. Mevcut install'ında personal access client yoksa (belirti: API login'de `RuntimeException: Personal access client not found for 'users'`), bir kerelik oluştur:

```bash
php artisan passport:client --personal --provider=users --name="$(php artisan config:show app.name)" --no-interaction
```

`oauth_clients` tablosuna `revoked=0` olan tek bir satır düşer. API token üretimi anında çalışmaya başlar — uygulama yeniden başlatmaya gerek yok.

### 8. Yeni additive özellikler — kod değişikliği gerekmez

Bu özellikler **otomatik devreye girer**, client'a yeni alanlar/header'lar gelir. Frontend takımını bilgilendirin:

| Özellik | Nerede görünür |
|---|---|
| `trace_id` (UUID) | Her JSON body (success ve error), ayrıca `X-Request-ID` header |
| `X-Correlation-ID` | Client `X-Request-ID` gönderirse sanitize edilip echo'lanır |
| `Retry-After` | 429 Too Many Requests response'ta |
| `simplePaginate()` desteği | `to_api(Model::simplePaginate(...))` artık type error vermeden çalışır; `meta.has_more` verir |
| "Postman'e Gönder" butonu | API Rotaları sayfası → yapılandırma tamamsa OpenAPI spec'ini Postman'e push eder |
| "Apidog'a Gönder" butonu | API Rotaları sayfası → yapılandırma tamamsa OpenAPI spec'ini Apidog'a push eder |
| Settings → API Clients tabı | Postman + Apidog kimlik bilgileri; `postman.api_key` / `apidog.access_token` DB'de encrypted |

### 9. Regresyon testi — opsiyonel ama tavsiye edilir

Paket `tests/Feature/Api/ApiResponseTest.php` içinde envelope şekli + exception mapping + trace_id + 204 + Retry-After + debug guard için 16 testlik bir kontrat dosyası shipliyor. App'inizde yoksa şuradan kopyalayın:

```bash
cp vendor/lvntr/laravel-starter-kit/tests/examples/ApiResponseTest.php \
   tests/Feature/Api/ApiResponseTest.php
php artisan test --compact --filter=ApiResponseTest
```

Beklenen: 16 test, 57 assertion geçer. Fail olursa test API middleware group'ta `AssignTraceId`'in aktif olup olmadığını kontrol edin.

### 10. Geri alma (rollback)

Sürüm geri çevrilirse:

```bash
git revert <upgrade-commit>
composer install
php artisan sk:update --force   # publish edilmiş dosyaları eski sürüme döndürür
```

`AssignTraceId.php` dosyası 13.4.x'te yoktu — rollback'ten sonra silin veya `Bootstrap.php`'nin eski sürümü sınıfı referans etmiyorsa bırakın (no-op).

---

## 13.3.x → 13.4.0 — Güvenlik hardening sprint'i

> **Özet:** Üç-katlı paralel kod inceleme bulguları sonrası ~37 bulgu kapatıldı (HIGH: 13 → 1 manuel, MEDIUM: 14, LOW: 4). Değişikliklerin büyük kısmı güvenlik (auth bypass, brute-force, XSS, log injection) ve veri bütünlüğü (DB transaction eksiklikleri). Yeni kurulumlar bu düzeltmeleri otomatik alır; **mevcut kurulumlar** bu dokümandaki patch listesini uygulamalıdır.

### 0. Kimi etkiler?

| Kullanıcı | Ne yapmalı |
| --- | --- |
| Paketi yeni kuranlar (`composer create-project` + `sk:install`) | Hiçbir şey — stubs zaten yeni sürümde. |
| Mevcut consumer app çalıştıranlar | Bu dokümandaki **Adım 1-8**'i takip edin. |
| Sadece paket `src/` kullananlar (publish yapmadı) | `composer update lvntr/laravel-starter-kit` yeter. |

### 1. Upgrade öncesi hazırlık

1. **Branch + yedek:** `git checkout -b upgrade/v13.4.0 && git push`
2. **DB yedeği:** Production için snapshot / dump.
3. **Ortam kontrolü:** `composer test` + `npm run build` mevcut sürümde geçiyor mu?
4. **PR sürecine dahil edin:** Bu güncellemenin büyük kısmı patch-stili; code review gerektirir.

### 2. Paket güncellemesi

```bash
composer update lvntr/laravel-starter-kit --with-all-dependencies
npm install
```

Bu adım Tier-1 değişiklikleri (paket `src/` içi) otomatik taşır:
- `SecurityHeaders` HSTS `preload` direktifi (`src/Http/Middleware/SecurityHeaders.php`)
- `MakeDomainCommand` / stub iyileştirmeleri

Kalan tüm değişiklikler publish edilmiş dosyalarda olduğu için **sizin app'inizdeki kopyayı** güncellemeniz gerekiyor.

---

### 3. HIGH — Güvenlik ve veri bütünlüğü patch'leri

Bunları **aynı sırada** uygulayın. Her biri bağımsız olarak çalışır ama sıralı commit temiz bir history oluşturur.

#### 3.1 (BE-H1) `UserPolicy::delete` + `Api\UserController::destroy` null guard

**Dosya:** `app/Policies/UserPolicy.php`

`delete()` metodundaki self-match dalını değiştirin:

```diff
     public function delete(User $actor, User $user): bool
     {
         if ($actor->is($user)) {
-            return true;
+            return false;
         }

         if (! $this->canManage($actor, $user)) {
             return false;
         }

         return $actor->can('users.delete');
     }
```

**Dosya:** `app/Http/Controllers/Api/UserController.php`

`destroy` metoduna null guard ekleyin:

```diff
     public function destroy(Request $request, User $user, DeleteUserAction $action): ApiResponse|JsonResponse
     {
         Gate::authorize('delete', $user);

+        $performedById = $request->user()?->id;
+        if ($performedById === null) {
+            return to_api(null, 'Unauthenticated.', 401);
+        }
+
         try {
-            $action->execute($user, (string) $request->user()?->id);
+            $action->execute($user, (string) $performedById);
             return to_api(status: 204);
```

**Test:** `DELETE /api/v1/users/{kendi_id}` 403 dönmeli (policy'de reddedildi), expired token ile 401 dönmeli.

---

#### 3.2 (BE-H2) `CreateRoleAction` + `UpdateRoleAction` DB transaction

**Dosya:** `app/Domain/Role/Actions/CreateRoleAction.php`

```diff
 use App\Models\Role;
 use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 ...
     public function execute(RoleDTO $dto): Role
     {
-        $role = Role::create($dto->toArray());
-        $role->syncPermissions($dto->permissions);
+        $role = DB::transaction(function () use ($dto): Role {
+            $role = Role::create($dto->toArray());
+            $role->syncPermissions($dto->permissions);
+
+            return $role;
+        });

         RoleCreated::dispatch($role, Auth::id());
         return $role;
     }
```

**Dosya:** `app/Domain/Role/Actions/UpdateRoleAction.php`

```diff
 use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 ...
         $oldPermissions = $role->permissions->pluck('name')->sort()->values()->all();

-        $role->update($data);
-        $role->refresh();
-        $role->syncPermissions($dto->permissions);
+        $role = DB::transaction(function () use ($role, $data, $dto): Role {
+            $role->update($data);
+            $role->refresh();
+            $role->syncPermissions($dto->permissions);
+
+            return $role;
+        });
```

---

#### 3.3 (BE-H3) `UpdateAuthSettingsAction` 2FA revoke transaction

**Dosya:** `app/Domain/Setting/Actions/UpdateAuthSettingsAction.php`

```diff
 use App\Models\User;
+use Illuminate\Support\Facades\DB;

 ...
     public function execute(AuthSettingsDTO $dto): void
     {
-        $wasTwoFactorEnabled = Setting::getValue('auth.two_factor', '1') === '1';
-        $isTwoFactorDisabled = $dto->twoFactor === '0';
-
-        Setting::setGroup('auth', $dto->toArray());
-
-        if ($wasTwoFactorEnabled && $isTwoFactorDisabled) {
-            $this->revokeAllTwoFactorAuth();
-        }
+        DB::transaction(function () use ($dto): void {
+            $wasTwoFactorEnabled = Setting::getValue('auth.two_factor', '1') === '1';
+            $isTwoFactorDisabled = $dto->twoFactor === '0';
+
+            Setting::setGroup('auth', $dto->toArray());
+
+            if ($wasTwoFactorEnabled && $isTwoFactorDisabled) {
+                $this->revokeAllTwoFactorAuth();
+            }
+        });
     }
```

---

#### 3.4 (BE-H4) `LogoutUserAction` null-safe

**Dosya:** `app/Domain/Auth/Actions/LogoutUserAction.php`

```diff
     public function execute(User $user): void
     {
-        $user->token()->revoke();
+        $user->token()?->revoke();
     }
```

Tek karakter — ama production'da active token olmayan kullanıcı logout isteğinde 500 hatası üretiyor.

---

#### 3.5 (BE-H5) FileManager N+1 düzeltmesi

**Dosyalar:** `app/Domain/FileManager/Actions/BulkDeleteAction.php` ve `DeleteFolderAction.php`.

Her iki dosyada `collectDescendantIds` metodunu değiştirin — owner scope'unda tek sorguyla `parent_id` haritasını çekip PHP tarafında BFS yapacak. Değişiklik hacmi büyük olduğu için tam yeni sürümleri `vendor/lvntr/laravel-starter-kit/stubs/app/Domain/FileManager/Actions/BulkDeleteAction.php` ve `DeleteFolderAction.php` dosyalarından kopyalayın.

**Ana değişiklikler:**
- `BulkDeleteAction`'a `buildChildrenMap(FileManagerContextDTO $context): array` eklendi. `collectDescendantIds($folder, $childrenByParent)` bu haritayı parametre alır.
- `DeleteFolderAction::collectDescendantIds`'e context parametresi eklendi; owner'a ait tüm klasör satırlarını tek sorguda çekip dolaşıyor.

50 seviyelik klasör ağacında 50 query → 1 query.

---

#### 3.6 (BE-H6) SMTP encryption `'none'` düzeltmesi

**Dosya:** `app/Providers/SettingsServiceProvider.php`

```diff
             if (array_key_exists('encryption', $mail)) {
-                config(['mail.mailers.smtp.encryption' => $mail['encryption']]);
+                // Laravel's SMTP mailer expects null (not the string "none") to send without TLS.
+                $encryption = $mail['encryption'] === 'none' ? null : $mail['encryption'];
+                config(['mail.mailers.smtp.encryption' => $encryption]);
             }
```

---

#### 3.7 (GV-H2 + GV-H3) `ApiExceptionHandler` — message leak + X-Request-ID injection

**Dosya:** `app/Exceptions/ApiExceptionHandler.php`

İki değişiklik:

**A) `handle()` metodunda trace ID üretimini değiştirin:**

```diff
     private static function handle(Throwable $e, Request $request): JsonResponse
     {
-        // 1. Trace ID — use client-provided value or generate a new one
-        $traceId = $request->header('X-Request-ID', (string) Str::uuid());
+        // 1. Trace ID — always server-generated to prevent log / header injection.
+        //    Any client-supplied X-Request-ID is accepted as correlation metadata
+        //    only after being sanitised and length-capped.
+        $traceId = (string) Str::uuid();
+        $clientRequestId = self::sanitizeClientRequestId($request->header('X-Request-ID'));

         // 2. Status + Message mapping
         [$status, $message] = self::resolve($e);

         // 3. Logging — 500+ non-validation errors
         if ($status >= 500 && ! ($e instanceof ValidationException)) {
             Log::error("[API {$status}] {$message}", [
                 'trace_id' => $traceId,
+                'client_request_id' => $clientRequestId,
                 'exception' => get_class($e),
                 ...
             ]);
         }
```

**B) `resolve()` metodundaki `default` dalını ve sınıfa yeni metodu ekleyin:**

```diff
-            // Unexpected errors
             default => [
                 500,
-                config('app.debug') ? $e->getMessage() : 'A server error occurred.',
+                'A server error occurred.',
             ],
         };
     }

+    /**
+     * Accept a client-provided X-Request-ID only if it matches a safe charset
+     * (letters, digits, dash, underscore, dot) and is ≤ 128 chars long.
+     */
+    private static function sanitizeClientRequestId(mixed $value): ?string
+    {
+        if (! is_string($value) || $value === '') {
+            return null;
+        }
+
+        $trimmed = substr($value, 0, 128);
+
+        return preg_match('/^[A-Za-z0-9._-]+$/', $trimmed) === 1 ? $trimmed : null;
+    }
```

---

#### 3.8 (FE-H1) Axios CSRF defaults

**Dosya:** `resources/js/app.ts`

Dosyanın en üstüne, import'ların hemen ardına ekleyin:

```diff
 import '../css/app.css';
 import 'primeicons/primeicons.css';
 import { createInertiaApp, usePage } from '@inertiajs/vue3';
+import axios from 'axios';
 import { i18nVue } from 'laravel-vue-i18n';
 ...
 import { PermissionPlugin } from '@/plugins/permission';

+// Axios defaults — send session + XSRF cookies on every request so Fortify
+// endpoints that rely on the web session (2FA, sessions, password-confirm)
+// stay CSRF-protected. XSRF cookie/header names match Laravel's defaults.
+axios.defaults.withCredentials = true;
+axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
+axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';
+axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
+axios.defaults.headers.common['Accept'] = 'application/json';
```

---

#### 3.9 (FE-H2) `TwoFactorTab` QR SVG XSS fix

**Dosya:** `resources/js/pages/Profile/components/TwoFactorTab.vue` (ya da legacy yol `pages/Profile/TwoFactorTab.vue`)

**A) `<script setup>` içinde — `qrCodeSvg` ref'inin altına ekleyin:**

```diff
     const qrCodeSvg = ref('');
     const setupKey = ref('');
     const recoveryCodes = ref<string[]>([]);
     const showRecoveryCodes = ref(false);

+    /**
+     * Render the Fortify QR SVG through an <img src="data:..."> element
+     * rather than v-html. An <img> sandbox neutralises any inline <script>
+     * or event handlers that a compromised intermediary could smuggle in.
+     */
+    const qrCodeDataUrl = computed<string>(() => {
+        if (!qrCodeSvg.value) return '';
+        try {
+            const encoded = window.btoa(unescape(encodeURIComponent(qrCodeSvg.value)));
+            return `data:image/svg+xml;base64,${encoded}`;
+        } catch {
+            return '';
+        }
+    });
```

**B) Template'te `v-html` bloğunu değiştirin:**

```diff
-                            <!-- eslint-disable vue/no-v-html -- QR SVG from trusted server -->
-                            <div class="inline-block rounded-lg bg-white p-4" v-html="qrCodeSvg" />
-                            <!-- eslint-enable vue/no-v-html -->
+                            <div class="inline-block rounded-lg bg-white p-4">
+                                <img
+                                    v-if="qrCodeDataUrl"
+                                    :src="qrCodeDataUrl"
+                                    :alt="$t('sk-profile.two_factor_scan')"
+                                    class="h-48 w-48"
+                                />
+                            </div>
```

---

#### 3.10 (FE-H3) `useDefinition.load()` error handling

**Dosya:** `resources/js/composables/useDefinition.ts`

`load()` ve `loadAll()` metodlarını `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useDefinition.ts` dosyasındaki yeni sürümle değiştirin. Ana değişiklik: `fetch` çağrısı `try/catch` içinde, `res.ok` kontrol ediliyor, hata durumunda `loaded.value` false bırakılıyor, console'a log atılıyor.

---

### 4. MEDIUM — Yetkilendirme, performans, UX

#### 4.1 (BE-M1) FormRequest `authorize(): true` temizliği

Aşağıdaki dosyalarda `return true;` yerine ilgili permission kontrolünü koyun:

| Dosya | Permission |
| --- | --- |
| `app/Http/Requests/Admin/User/StoreUserRequest.php` | `users.create` |
| `app/Http/Requests/Api/User/StoreUserRequest.php` | `users.create` |
| `app/Http/Requests/Admin/Role/StoreRoleRequest.php` | `roles.create` |
| `app/Http/Requests/Admin/Settings/UpdateAuthSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateGeneralSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateMailSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateStorageSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateFileManagerSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateTurnstileSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/SendTestMailRequest.php` | `settings.update` |

```diff
     public function authorize(): bool
     {
-        return true;
+        return $this->user()?->can('users.create') ?? false;
     }
```

(Permission adını uygun olanla değiştirin.)

Ek olarak `app/Http/Requests/DestroySessionsRequest.php`:

```diff
-        return true;
+        return $this->user() !== null;
```

**Auth / public endpoint'lere dokunmayın:** `Api/Auth/LoginRequest.php`, `RegisterRequest.php`, `TwoFactorChallengeRequest.php` public kalır.

**FileManager endpoint'lerine dokunmayın:** `FileManagerRequest.php` ve alt sınıflar context-tabanlı yetkilendirme kullanır.

---

#### 4.2 (BE-M4) TwoFactorChallenge brute-force hardening

**Dosya:** `app/Domain/Auth/Actions/TwoFactorChallengeAction.php`

Üç başarısız dala da `Cache::forget($cacheKey)` ekleyin — challenge artık tek kullanımlık:

```diff
         if ($code !== null && $code !== '') {
             $valid = $this->provider->verify(...);

             if (! $valid) {
+                Cache::forget($cacheKey);
+
                 return null;
             }
         } elseif ($recoveryCode !== null && $recoveryCode !== '') {
             $match = collect($user->recoveryCodes())->first(...);

             if ($match === null) {
+                Cache::forget($cacheKey);
+
                 return null;
             }

             $user->replaceRecoveryCode($match);
         } else {
+            Cache::forget($cacheKey);
+
             return null;
         }
```

Route tarafındaki `throttle:5,1` zaten mevcut.

---

#### 4.3 (BE-M7 + BE-M12) `SettingService` transaction + cache

**Dosya:** `app/Domain/Setting/SettingService.php`

Tüm dosyayı `vendor/lvntr/laravel-starter-kit/stubs/app/Domain/Setting/SettingService.php` dosyasından kopyalamak en kolayı. Özetle:

1. `DB` facade import'u eklendi.
2. `getValue()` ve `getGroup()` artık `allGrouped()` cache'i üstünden okuyor — tekil sorgu yok.
3. `setGroup()` `DB::transaction(...)` içine alındı.

Davranış aynı, performans ve atomisite yükseldi.

---

#### 4.4 (BE-M8) `MoveItemRequest` validation sıkılaştırma

**Dosya:** `app/Http/Requests/FileManager/MoveItemRequest.php`

```diff
 <?php

 namespace App\Http\Requests\FileManager;

+use Illuminate\Validation\Rule;
+
 class MoveItemRequest extends FileManagerRequest
 {
     public function rules(): array
     {
+        $itemType = $this->input('item_type');
+
+        $itemIdRules = ['required'];
+        if ($itemType === 'file') {
+            $itemIdRules = ['required', 'integer', 'min:1'];
+        } elseif ($itemType === 'folder') {
+            $itemIdRules = ['required', 'uuid'];
+        }
+
         return [
             ...$this->contextRules(),
-            'item_type' => ['required', 'string', 'in:folder,file'],
-            'item_id' => ['required'],
+            'item_type' => ['required', 'string', Rule::in(['folder', 'file'])],
+            'item_id' => $itemIdRules,
             'target_folder_id' => ['nullable', 'uuid'],
         ];
     }
 }
```

---

#### 4.5 (BE-M9) `DeleteFolderRequest` FormRequest

**Yeni dosya:** `app/Http/Requests/FileManager/DeleteFolderRequest.php`

```php
<?php

namespace App\Http\Requests\FileManager;

class DeleteFolderRequest extends FileManagerRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->contextRules();
    }
}
```

**Dosya:** `app/Http/Controllers/FileManagerController.php`

Use satırına ekleyin + metod signature değiştirin:

```diff
 use App\Http\Requests\FileManager\BulkDeleteRequest;
+use App\Http\Requests\FileManager\DeleteFolderRequest;
 use App\Http\Requests\FileManager\MoveItemRequest;
 ...

-    public function deleteFolder(Request $request, FileFolder $folder, DeleteFolderAction $action): ApiResponse
+    public function deleteFolder(DeleteFolderRequest $request, FileFolder $folder, DeleteFolderAction $action): ApiResponse
     {
-        $context = $this->contextFromRequest($request);
+        $context = $request->context();
         $this->authorizer->authorizeWrite($context);
```

---

#### 4.6 (BE-M10) `uploadAvatar` Gate::authorize tutarlılığı

**Dosya:** `app/Http/Controllers/Admin/UserController.php`

```diff
     public function uploadAvatar(UploadAvatarRequest $request, User $user, UploadMediaAction $action): ApiResponse
     {
+        Gate::authorize('update', $user);
+
         $action->execute($user, $request, 'avatar');
```

---

#### 4.7 (FE-M1) `useDialog` timer leak

**Dosya:** `resources/js/composables/useDialog.ts`

Tam sürüm için `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useDialog.ts`'e bakın. Değişiklikler:

1. `state`'in altına module seviyesinde `let closeTimer: ReturnType<typeof setTimeout> | null = null;` eklendi.
2. `open()` başında `clearTimeout(closeTimer)` + `closeTimer = null`.
3. `close()` başında da aynı clear, sonra `closeTimer = setTimeout(..., 300)`, timeout body'sinde `closeTimer = null`.

---

#### 4.8 (FE-M2) `useImageLightbox` timer leak

`useDialog` ile aynı pattern. `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useImageLightbox.ts`'den kopyalayın.

---

#### 4.9 (FE-M4) `SkForm` isDirty guard — veri kaybı koruması

**Dosya:** `resources/js/components/Lvntr-Starter-Kit/FormBuilder/SkForm.vue` (veya paket importu kullanıyorsanız bu değişiklik `composer update` ile gelir — paket kaynağı düzeltildi).

`watch(derivedDefaults, …)` bloğuna isDirty dalı ekleyin:

```diff
     watch(derivedDefaults, (newValues, oldValues) => {
         if (!isInternalMode.value) {
             return;
         }
         if (oldValues && shallowRecordEqual(newValues, oldValues)) {
             return;
         }
+        if (internalForm.isDirty) {
+            internalForm.defaults(newValues);
+            return;
+        }
         restoringDefaults.value = true;
```

---

#### 4.10 (FE-M6) `SkDatatable` urlFilters api.get

**Dosya:** `resources/js/components/Lvntr-Starter-Kit/DatatableBuilder/SkDatatable.vue`

```diff
     if (urlFilters.length) {
         onMounted(async () => {
-            await Promise.all(
+            await Promise.allSettled(
                 urlFilters.map(async (f) => {
-                    const res = await fetch(f.optionsUrl!, {
-                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
-                        credentials: 'same-origin',
-                    });
-                    const json = await res.json();
-                    urlOptions[f.key] = json.data ?? json;
+                    try {
+                        const data = await api.get<FilterOption[]>(f.optionsUrl!);
+                        urlOptions[f.key] = data ?? [];
+                    } catch {
+                        urlOptions[f.key] = [];
+                    }
                 }),
             );
         });
     }
```

Aynı dosyada `let activeMenuItems = ref<MenuItem[]>([]);` → `const activeMenuItems = ref<MenuItem[]>([]);` (FE-M9).

---

#### 4.11 (FE-M7) `TwoFactorTab` router.reload await

**Dosya:** `resources/js/pages/Profile/components/TwoFactorTab.vue`

```diff
     async function enableTwoFactor() {
         twoFactorProcessing.value = true;

         if (!props.twoFactorEnabled) {
             await axios.post('/user/two-factor-authentication');
-            router.reload({ only: ['twoFactorEnabled', 'twoFactorConfirmed'] });
+            await new Promise<void>((resolve) => {
+                router.reload({
+                    only: ['twoFactorEnabled', 'twoFactorConfirmed'],
+                    onFinish: () => resolve(),
+                });
+            });
         }

         await loadQrAndSetupKey();
```

---

#### 4.12 (FE-M8) `as any` cast'leri temizleyin

**Dosya:** `resources/js/pages/Profile/components/ProfileInfoTab.vue`

```diff
-        :avatar-url="(user as any)?.avatar_url"
+        :avatar-url="user?.avatar_url"
```

**Dosya:** `resources/js/pages/Admin/Users/components/UserForm.vue`

```diff
-            :avatar-url="(formRef.remoteData as any)?.avatar_url"
+            :avatar-url="(formRef.remoteData as { avatar_url?: string | null } | null)?.avatar_url"
```

---

### 5. Config / Env hardening

#### 5.1 (GV-M1) `.env.example` ve `.env`'de LOG_LEVEL

**Dosya:** `.env.example`

```diff
-LOG_LEVEL=debug
+LOG_LEVEL=error
```

Production `.env`'lerde de `LOG_LEVEL=error` ya da `warning` olduğundan emin olun.

---

#### 5.2 (GV-M2) Tinker `require` → `require-dev`

**Dosya:** `composer.json`

```diff
     "require": {
         "php": "^8.3",
         "laravel/framework": "^13.0",
         "laravel/pulse": "^1.7",
-        "laravel/tinker": "^2.10.1 || ^3.0",
         "lvntr/laravel-starter-kit": "@dev"
     },
     "require-dev": {
         ...
         "laravel/sail": "^1.41",
+        "laravel/tinker": "^2.10.1 || ^3.0",
         "mockery/mockery": "^1.6",
```

Sonra: `composer update`.

---

#### 5.3 (GV-M3, GV-M4) `.env.example` — Turnstile & Passport key placeholder'ları

**Dosya:** `.env.example`

Passport bölümünün altına ekleyin:

```
# Passport OAuth2 keys — prefer loading via env in production instead of
# committing the key files at storage/oauth-*.key. Run `php artisan passport:keys`
# once, move the generated strings into these env vars, then delete the files.
# PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
# PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"

# Cloudflare Turnstile (bot / captcha). When TURNSTILE_ENABLED=false the
# `turnstile` middleware becomes a no-op, so leaving the keys empty during
# development is safe.
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

---

#### 5.4 (GV-M5) `HandleInertiaRequests` — appEnv / appDebug scope

**Dosya:** `app/Http/Middleware/HandleInertiaRequests.php`

```diff
             'appVersion' => InstalledVersions::getPrettyVersion('lvntr/laravel-starter-kit'),
-            'appEnv' => config('app.env'),
-            'appDebug' => config('app.debug'),
+            'appEnv' => fn () => app()->environment('production') ? null : config('app.env'),
+            'appDebug' => fn () => app()->environment('production') ? false : (bool) config('app.debug'),
```

Eğer frontend'te `appEnv === 'production'` kontrolü yapan kod varsa artık `null` bekleyecek şekilde güncellenmeli.

---

#### 5.5 (GV-M7) CORS preflight cache

**Dosya:** `config/cors.php`

```diff
-    'max_age' => 0,
+    // Cache preflight (OPTIONS) results in the browser for 2 hours so SPA /
+    // mobile clients don't re-run the CORS handshake on every mutating call.
+    'max_age' => 7200,
```

---

#### 5.6 (GV-L1) `Password::defaults` policy

**Dosya:** `app/Providers/AppServiceProvider.php`

```diff
 use Illuminate\Support\Facades\Event;
 use Illuminate\Support\ServiceProvider;
+use Illuminate\Validation\Rules\Password;

 class AppServiceProvider extends ServiceProvider
 {
     ...
     public function boot(): void
     {
         Event::listen(Login::class, UpdateLastLogin::class);
+
+        Password::defaults(function () {
+            return Password::min(10)
+                ->mixedCase()
+                ->letters()
+                ->numbers()
+                ->symbols();
+        });
     }
 }
```

**Uyarı:** Bu değişiklik mevcut kullanıcıların şifrelerini geçersiz KILMAZ, ama yeni kayıt / şifre değiştirme akışlarında artık 10+ karakter, karışık büyük/küçük, rakam ve sembol zorunlu.

---

### 6. GV-H1 — Passport private key rotasyonu (KRİTİK, MANUEL)

Bu adım destructive işlemler içerir; **iş günü dışında, takım onayı + rollback planıyla** uygulayın.

```bash
# 1. git-filter-repo kur (filter-branch deprecated)
brew install git-filter-repo          # veya: pipx install git-filter-repo

# 2. Key dosyalarını history'den sil
cd /yolu/starter-kit-app
git filter-repo --path storage/oauth-private.key --invert-paths
git filter-repo --path storage/oauth-public.key  --invert-paths

# 3. Yeni key üret (geçici olarak dosya kalsın)
php artisan passport:keys --force

# 4. İçeriği .env'e geçir, dosyaları sil
# (PASSPORT_PRIVATE_KEY ve PASSPORT_PUBLIC_KEY — config/passport.php zaten okuyor)
rm storage/oauth-private.key storage/oauth-public.key

# 5. Aktif token'ları purge et
php artisan passport:purge

# 6. Force push (takım onayı şart)
git push --force-with-lease origin <branch>
```

**Dikkat:**
- Tüm ekibin force-push sonrası `git fetch && git reset --hard origin/<branch>` yapması gerekir.
- CI / CD sunucularında kayıtlı repo kopyaları da temizlenmeli.
- `PASSPORT_*` env değerleri production vault / secrets manager'a eklenmeli (git'e ASLA commit edilmemeli).

---

### 7. Doğrulama

```bash
# Backend
composer install
php artisan migrate --force
php artisan sk:seed-permissions --fresh
vendor/bin/pint --dirty --format agent

# Frontend
npm install
npm run build

# Tests
php artisan test --compact
npm run test
```

Her şey yeşile dönene kadar commit etmeyin. Bir test başarısız olursa ilgili patch'i izole edip hot-fix yapın; bu sürümdeki diğer patch'lere ertelemeyin — hepsi bağımsız.

### 8. Son kontrol — smoke test senaryoları

- [ ] Login → 2FA challenge → kod yanlış → tek hakkı tüketir (BE-M4).
- [ ] API `DELETE /api/v1/users/{kendi_id}` 403 döner (BE-H1).
- [ ] Role create + permission atama: DB'ye yansıyor (BE-H2).
- [ ] Settings > Auth sayfasından 2FA kapat: tüm kullanıcıların 2FA secret'ları temizleniyor + setting kaydedildi (BE-H3).
- [ ] Büyük klasör (50+ seviye) bulk delete: sayfa timeout olmuyor (BE-H5).
- [ ] SMTP encryption "none" seçili: mail gönderimi başarılı (BE-H6).
- [ ] `APP_DEBUG=true` iken 500 hatası alan API endpoint: response `message` generic; detay `debug` bloğunda (GV-H2).
- [ ] `X-Request-ID: ../etc/passwd` header'ı ile istek: response header `X-Request-ID` UUID formatında; log'da `client_request_id: null` (GV-H3).
- [ ] 2FA kurulum sayfası: QR kod `<img>` olarak render, `v-html` yok (FE-H2).
- [ ] Dialog aç/kapat/aç hızlı yapınca içerik silinmiyor (FE-M1).
- [ ] FormBuilder formu açıldıktan sonra parent prop değişirse: kullanıcının yazdığı input silinmiyor (FE-M4).

---

## Sorun giderme

### Genel

**`sk:update` sonrası sınıflar bulunamıyorsa:**

```bash
composer dump-autoload
```

**`sk:update` sonrası Vite manifest hatası:**

```bash
npm run build
# veya dev sunucusunu başlatın
npm run dev
```

**`sk:update` sonrası migration hatası:**

`migrate:fresh` / `migrate:refresh`'e başvurmayın — bu projenin migration'ları artımlıdır ve tek tek çalıştırılabilir. Başarısız migration'ı düzeltin (ya da `php artisan migrate:rollback --step=1` ile geri alın) ve `php artisan migrate`'i tekrar çalıştırın.

**Yükseltme sonrası Passport anahtarları eksikse:**

```bash
php artisan passport:keys --force
```

### "422 Unprocessable Content" — yeni FormRequest authorize
Yeni `authorize()` kontrolü sert. İlgili permission'ın user'a atanmış olduğundan emin olun: `php artisan sk:seed-permissions --fresh` çalıştırın.

### 2FA doğrulamasında "challenge expired"
BE-M4 sonrası tek deneme hakkı var. 6 haneli kodu yanlış girerseniz tüm akış baştan başlar — Fortify OTP uygulamasındaki yeni kodu (30 saniyede bir rotates) alıp login'e yeniden girin.

### Axios istekleri 419 dönmüyor ama session yok
FE-H1 sonrası `withCredentials = true`. Eğer front-end'iniz farklı bir domain'den geliyorsa (subdomain dahil) `config/cors.php` içinde `supports_credentials => true` olmalı + allowed_origins wildcard içermemeli.

### Dashboard boş görünüyor
`appEnv` / `appDebug` artık prod'da `null` / `false` — Vue template'te koşullu rendering varsa fallback değer kullandığından emin olun.

---

## Önceki sürümler

- **13.3.3** (2026-04-20) — Windows build fix: Builder `core/` import'ları için sibling `core.ts` barrel. Detaylar: [CHANGELOG.md](CHANGELOG.md).
- **13.3.2** (2026-04-19) — Güvenlik hardening + user audit + API auth parity. Detaylar: [CHANGELOG.md](CHANGELOG.md).

Tam değişiklik tarihçesi için [CHANGELOG.md](CHANGELOG.md)'ye bakın.
