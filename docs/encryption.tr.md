# Veri Şifreleme

Starter kit veritabanındaki küçük bir hassas veri kümesini şifreler. Bu döküman hangi anahtarın bu veriyi koruduğunu, bu anahtarın `APP_KEY` ile ilişkisini ve adanmış anahtarı benimserken/rotasyon yaparken çalıştırılacak komutları anlatır.

## Ne şifreleniyor, hangi anahtarla

| Veri | Nerede | Kullanılan anahtar |
| --- | --- | --- |
| Hassas ayar değerleri (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) | `settings.value` | `DataCrypt` (adanmış anahtar) |
| İki faktörlü doğrulama sırrı ve kurtarma kodları | `users.two_factor_secret`, `users.two_factor_recovery_codes` | `DataCrypt` (adanmış anahtar) |
| Session'lar, imzalı URL'ler, cookie'ler ve hâlâ `Crypt` facade'ını çağıran uygulama kodu | — | `APP_KEY` (değişmedi) |

`DataCrypt` (`Lvntr\StarterKit\Support\Encryption\DataCrypt`), Laravel'in `Crypt` facade'ıyla aynı API'ye sahip bir facade'dır (`encryptString()`, `decryptString()`, `encrypt()`, `decrypt()`). Tek fark hangi anahtarın kullanıldığıdır.

## `APP_KEY` vs `DATA_ENCRYPTION_KEY`

Bunlar bağımsız yaşam döngülerine sahip iki ayrı anahtardır:

- **`APP_KEY`** — Laravel'in kendi anahtarı. Session'ları, cookie'leri, imzalı URL'leri ve `Crypt` facade'ını destekler. `php artisan key:generate` bu anahtarı yeniden üretir.
- **`DATA_ENCRYPTION_KEY`** — yukarıda listelenen ayar ve 2FA verisi için adanmış bir anahtar. Kendi `.env` değişkenine ve kendi komutlarına (`encryption:key`, `encryption:rekey`, `encryption:health`) sahiptir. **`php artisan key:generate` `DATA_ENCRYPTION_KEY` veya `DATA_ENCRYPTION_PREVIOUS_KEYS`'i asla okumaz veya yazmaz — yalnızca `APP_KEY`'e dokunur.**

Bu ayrım şu nedenle var: `key:generate` çalıştığı anda her şifreli ayarı ve her kullanıcının 2FA sırrını sessizce bozuyordu (`Crypt`/Fortify'ın varsayılan encrypter'ı `APP_KEY`'e bağlı, `SettingService` de ortaya çıkan `DecryptException`'ı yutup `null` döndürüyor, dolayısıyla hata görünmüyordu bile). Adanmış anahtar bu bağımlılığı kaldırır.

## Anahtar çözümleme sözleşmesi

Bu tablo bütün güvenlik özelliğidir. `DataEncrypterFactory` tarafından uygulanır ve `DataCrypt` tarafından zorlanır.

| Durum | Birincil anahtar (yazma) | Previous-key zinciri (okuma) |
| --- | --- | --- |
| `DATA_ENCRYPTION_KEY` boş (adanmış anahtarı henüz benimsememiş bir kurulum) | `APP_KEY` | `DATA_ENCRYPTION_PREVIOUS_KEYS` (varsa) |
| `DATA_ENCRYPTION_KEY` dolu | `DATA_ENCRYPTION_KEY` | `DATA_ENCRYPTION_PREVIOUS_KEYS`, ardından **en sonda `APP_KEY`** |

- `APP_KEY` birincil anahtar değilse *her zaman* okuma zincirinin sonuna eklenir — benimseme öncesi yazılmış her şey hiçbir komut çalıştırmadan okunabilir kalır.
- Zincir sırayla denenir, tekrarlar temizlenir, boş elemanlar atılır.
- Zincirdeki bozuk bir anahtar sessizce atlanmaz — `RuntimeException` fırlatılır. Sessiz atlama bir değeri rotasyon ortasında okunamaz gibi gösterip operatörü `DATA_ENCRYPTION_PREVIOUS_KEYS`'i boşaltmaya iter — bu ise kalıcı veri kaybıdır.

**Hiçbir şey yapmamak geçerli bir tercihtir.** `encryption:key`'i hiç çalıştırmayan bir uygulama bugünküyle bit-bit aynı çalışmaya devam eder: `DATA_ENCRYPTION_KEY` boş kalır, birincil anahtar `APP_KEY` olmaya devam eder, davranış değişmez. `composer update`/`sk:update` sonrası hiçbir şey benimsemeyi zorlamaz.

## Üç komut

| Komut | Amaç | Diske yazar mı? |
| --- | --- | --- |
| `php artisan encryption:key` | Yeni bir `DATA_ENCRYPTION_KEY` üretir ve yerini aldığı anahtarı `DATA_ENCRYPTION_PREVIOUS_KEYS`'te korur | Evet — `.env` |
| `php artisan encryption:rekey` | Her ayar/2FA satırını mevcut birincil anahtara yeniden şifreler | Evet — veritabanı (`.env` değil) |
| `php artisan encryption:health` | Her satırın hangi anahtara ihtiyacı olduğunu ve `DATA_ENCRYPTION_PREVIOUS_KEYS`'in temizlenmesinin güvenli olup olmadığını raporlar | Hayır — salt okunur |

### `encryption:key`

```bash
php artisan encryption:key
```

- Mevcut birincil anahtarı `.env`'den çözer (önbelleklenmiş config'ten değil), yapılandırılmış cipher için bellekte rastgele yeni bir anahtar üretir, `DATA_ENCRYPTION_PREVIOUS_KEYS`'i eski birincili başa ekleyerek yazar ve ancak ondan sonra yeni `DATA_ENCRYPTION_KEY`'i yazar. Bu sıra kasıtlıdır: iki yazma arasında bir çökme, eski anahtarı hâlâ birincil ve fazladan listelenmiş bırakır — asla tersi değil, tersi her şifreli satırı sahipsiz bırakırdı.
- `.env`, uygulamanın önyüklendiği aynı ayrıştırıcıyla (phpdotenv) okunur, regex ile değil — `${VAR}` ile enterpolasyonlu bir atama (örn. `DATA_ENCRYPTION_KEY=${APP_KEY}`), `DATA_ENCRYPTION_PREVIOUS_KEYS`'e yazılmadan önce gerçek anahtar materyaline çözülür, hiçbir zaman `${APP_KEY}` referansı olarak değil. Ayrıştırıcının anlam veremediği bir `.env`, hiçbir anahtar üretilmeden veya hiçbir şey yazılmadan önce komutu durdurur — yarım uygulanmış bir rotasyon kalmaz — ve ayrıştırıcının kendi hata mesajı gösterilmez, çünkü bozuk satırı olduğu gibi alıntılayabilir ve bu satır anahtar materyali olabilir.
- **Yetki dosyada olmalı.** Süreç ortamı bu anahtarlardan birini ayarlıyorsa — ya da `.env` içindeki enterpolasyonlu bir değerin işaret ettiği bir değişkeni ayarlıyorsa — çalışan uygulama dosyanın söylediği değeri değil, o değeri çözer. `encryption:key` bu ayrışmayı tespit edip hiçbir şey üretmeden durur; anahtarın adını söyler, iki değerden hiçbirini yazmaz. `.env`'i yeniden yazmak işe yaramaz: süreçteki değer kazanmaya devam eder ve rotasyon, uygulamanın hiç kullanmadığı bir anahtarı emekliye ayırıp gerçekten kullandığını listeden düşürür — mevcut şifreli veri okunamaz hale gelir. Süreç değişkenini kaldırın (ya da dosyayla aynı değere getirin) ve komutu tekrar çalıştırın.
- `APP_KEY` bu komut tarafından hiçbir seçenekte okunmaz, değiştirilmez, yeniden yazılmaz.
- `--show` yeni üretilen bir anahtarı stdout'a yazar, hiçbir şey diske yazmaz. `.env`'e dokunmadan bir anahtarın neye benzediğine bakmak için kullanın.
- Production gibi görünen bir ortamda çalıştırmak için `--force` gerekir, çünkü anahtarı burada döndürmek `encryption:rekey` tamamlanana kadar her şifreli değeri okunamaz yapar. `--force`'u ancak bir veritabanı yedeğiniz ve bir bakım penceresi varken tekrar çalıştırın.
- Yeni anahtar `.env`'e yazılır ama asla ekrana basılmaz. Yerini aldığı anahtar da asla basılmaz veya loglanmaz — çıktıda yalnızca geldiği değişkenin adı görünür.

### `encryption:rekey`

```bash
php artisan encryption:rekey
php artisan encryption:rekey --dry-run
php artisan encryption:rekey --only=settings
php artisan encryption:rekey --only=two-factor
php artisan encryption:rekey --chunk=200
```

- Birincil olmayan bir anahtarla çözülen her ayar ve 2FA satırını mevcut birincil anahtara yeniden şifreler. Yapılandırılmış hiçbir anahtarın okuyamadığı bir satır dokunulmadan bırakılır ve raporlanır — asla silinmez veya boşaltılmaz.
- `--dry-run` her çözme denemesini yapar ve aynı özeti tek bir bayt yazmadan basar.
- `--only=settings` veya `--only=two-factor` (birleştirmek için virgülle) çalıştırmayı tek bir yüzeyle sınırlar.
- `--chunk=<n>` (varsayılan 200, maksimum 2000) her gidiş-dönüşte kaç satırın okunup kilitlenip yeniden yazılacağını kontrol eder.
- Bu komut bir **bakım penceresine** aittir. Her chunk'ı bir transaction altında yeniden okur ve kilitler, böylece eşzamanlı bir yazma bayat bir yeniden yazmayla ezilmez — ama büyük bir rekey'in yoğun trafikli bir production veritabanında planlı bir pencere dışında çalıştırılmaması gerekir.
- Rekey hiçbir `updated_at`'i güncellemez — bu bir depolama formatı değişikliğidir, iş kuralı değişikliği değil.

### `encryption:health`

```bash
php artisan encryption:health
php artisan encryption:health --json
```

Salt okunur — kilit almaz, transaction açmaz, canlı bir veritabanına karşı çalıştırmak güvenlidir.

Sonuçlar (exit kodu makine tarafından okunabilir yarısıdır):

| Sonuç | Exit kodu | Anlamı |
| --- | --- | --- |
| `safe-to-clear` | 0 | Taranan her değer yalnızca birincil anahtarla okunuyor; her yüzey tamamen tarandı. `DATA_ENCRYPTION_PREVIOUS_KEYS` temizlenebilir. |
| `rekey-required` | 1 | En az bir değer birincil olmayan bir anahtara ihtiyaç duyuyor. Henüz hiçbir şey kaybolmadı, ama previous-key listesini şimdi temizlemek kaybettirir. `encryption:rekey` çalıştırın. |
| `not-covered` | 1 | Bir yüzey, bu kitin kurmadığı bir encrypter tarafından servis ediliyor ya da `starter-kit.encryption` config bloğu yok olduğu için yapılandırılan anahtar etkisiz. Hiçbir şey kaybolmadı, ama aşağıdaki atıf kurulumun gerçek okuma/yazma yolunu tarif etmiyor; bu yüzden buradan "safe to clear" iddia edilemez. Bkz. aşağıdaki [Yüzey kapsamı](#yüzey-kapsamı). |
| `incomplete` | 1 | Bir yüzey tam olarak taranamadı, dolayısıyla "safe" iddia edilemez — çözdüğü anahtar zinciri artık `.env`/süreç ortamıyla eşleşmeyen önbelleklenmiş bir config de buna dahildir. `php artisan config:clear` çalıştırın (production'da config önbelleği kullanıyorsanız ardından yeniden önbelleğe alın) ve tekrar çalıştırın. |
| `unreadable` | 2 | Yapılandırılmış hiçbir anahtarın okuyamadığı bir değer var. Onu yazan anahtar `.env`'den eksik ve geri eklenmeli — asla temizlenmemeli. |
| `key-error` | 2 | Anahtar zincirinin kendisi çözülmüyor; hiçbir şey atfedilemedi. |

Sonuç yalnızca aşağı düşer, asla yükselmez — yanlış bir "safe to clear" bu komutun veri kaybına yol açabilecek tek çıktısıdır.

### Yüzey kapsamı

Her iki komut da yalnızca kitin kendi anahtar zincirini değil, **her yüzeyi gerçekte hangi encrypter'ın servis ettiğini** raporlar. Bu önemlidir, çünkü 2FA yüzeyi zorunlu olarak kit üzerinden okunmaz: Fortify `Fortify::$encrypter ?? Model::$encrypter ?? Crypt` sırasını çözer ve kit, tüketicinin kendi ayarladığı bir encrypter'ı bilinçli olarak ezmez (`StarterKitServiceProvider::configureDataEncryption()`).

- `encryption:health` her yüzeyin arkasındaki encrypter'ı isimlendirir. Kitin kurmadığı bir encrypter tarafından servis edilen yüzey **unvouched** (kefil olunamayan) olarak raporlanır ve sonuç `not-covered`'a (exit 1) düşer — satır atfı saklanan baytlar hakkında hâlâ doğrudur, ama bu kurulumun okuyup yazdığı yol hakkında hiçbir şey söylemez.
- **Eski yayınlanmış config boşluğu ayrı bir teşhis olarak raporlanır.** Encryption bloğundan önce yayınlanmış bir `config/starter-kit.php`, `starter-kit.encryption` değerini null yapar; böylece `DATA_ENCRYPTION_KEY` etkisiz kalır ve birincil anahtar sessizce `APP_KEY` yedeğine düşer — bu durum önceden "safe to clear" olarak okunabiliyordu. Artık `not-covered` verir; config'i yeniden yayınlayın (`php artisan vendor:publish --tag=starter-kit-config --force`) ve tekrar çalıştırın.
- Seçilen bir yüzey unvouched ise `encryption:rekey` **tek bir satır bile okumadan reddeder** ve hem yüzeyi hem de onu dışarıda bırakan `--only=` bayrağını isimlendirir. Sessizce daraltılıp ardından eksiksiz bir rekey gibi raporlanan bir çalıştırma, hatadan daha kötüdür: satırları, okuyan encrypter'ın elinde olmayan bir anahtara yeniden yazar ve her 2FA girişini başarısız bir challenge'a çevirir. Seçilen yüzeylerin tamamı kapsam içindeyse çalıştırma bundan etkilenmez.

## Mevcut bir kurulumda adanmış anahtarı benimsemek

`encryption:key`'i hiç çalıştırmamış bir kurulumun bunu yapması zorunlu değildir. Adanmış anahtarı benimsemeyi seçerseniz şu dört adımı sırayla çalıştırın:

```bash
php artisan encryption:key
php artisan config:clear   # config önbelleklenmişse rekey HÂLÂ eski birincil anahtarı çözer
php artisan encryption:rekey
php artisan encryption:health
```

`config:clear` iki komutun **arasında** durur, sonrasında değil. `encryption:key` `.env`'i yazar ama önbelleklenmiş config önceki zinciri sunmaya devam eder — dolayısıyla temizlemeden önce çalıştırılan bir rekey, her satırı az önce emekliye ayrılan anahtara yeniden şifreler ya da yapacak hiçbir şey bulamaz. Önce temizlemek, `encryption:rekey`'in veriyi taşıması gereken anahtarı görmesini sağlayan adımdır.

Ardından, yalnızca `encryption:health` `safe-to-clear` raporladıktan sonra:

```bash
# .env'i elle düzenleyin ve değeri temizleyin:
DATA_ENCRYPTION_PREVIOUS_KEYS=
```

```bash
php artisan config:clear
php artisan encryption:health
```

Production'da config önbelleği kullanıyorsanız yukarıdaki her `config:clear`'dan hemen sonra `php artisan config:cache`'i tekrar çalıştırın.

Düzenlemeden sonraki ikinci `encryption:health` çalıştırması da `safe-to-clear` raporlamadan `DATA_ENCRYPTION_PREVIOUS_KEYS`'i temizlemeyin. Başka bir şey raporlarsa eski değeri geri koyun ve araştırın — her satır birincil anahtarda doğrulanmadan previous-key listesini temizlemek, bir rotasyonu kalıcı veri kaybına çevirir.

## Zaten benimsenmiş bir anahtarı rotasyona sokmak

Rotasyon aynı üç komutu, aynı sırayla, aynı bakım penceresi gerekliliğiyle kullanır:

```bash
php artisan encryption:key --force   # --force yalnızca production gibi bir ortamda gerekli
php artisan config:clear             # config önbelleklenmişse rekey HÂLÂ eski birincil anahtarı çözer
php artisan encryption:rekey
php artisan encryption:health
```

Ardından `DATA_ENCRYPTION_PREVIOUS_KEYS`'i elle temizleyin, `config:clear` çalıştırın ve yukarıdaki benimseme akışındaki gibi tekrar `encryption:health` çalıştırıp doğrulayın. `encryption:rekey`'i bir bakım penceresi içinde çalıştırın — komut eşzamanlı okuma/yazmaya karşı güvenli yazılmış olsa da büyük bir tabloyu aktif trafik ortasında rekey'lemek doğru değildir.

## Bu özelliğin geri alınması (rollback)

Şifreleme kodunun kendisi geri alınırsa (`git revert`), `DataCrypt` var olmaktan çıkar ve her çağrı noktası `APP_KEY`'e bağlı olan `Crypt`'e düşer. Adanmış anahtarla yazılmış her satır, revert anında okunamaz hale gelir — **önce şunu yapmadıkça:**

1. `.env`'de `DATA_ENCRYPTION_KEY`'i `APP_KEY` ile tam olarak aynı değere ayarlayın.
2. Her satırın o ortak değere yeniden şifrelenmesi için `php artisan encryption:rekey` çalıştırın.
3. Kod revert'i ancak ondan sonra uygulayın.

Rotasyon yalnızca kısmen uygulandıysa (örn. `encryption:key` çalıştı ama `encryption:rekey` bitmedi), `DATA_ENCRYPTION_KEY`'i önceki değerine geri döndürün ve `DATA_ENCRYPTION_PREVIOUS_KEYS`'e dokunmayın — zincir sırayla denendiği için veri okunabilir kalır. `encryption:health` ile doğrulayın.

## Ayrıca bakınız

- `docs/server-migration-runbook.md` — kurulu bir uygulamayı şifreli veri kaybetmeden yeni bir sunucuya taşımak için kopyala-yapıştır kontrol listesi.
- `docs/artisan-commands.md` — tam komut referansı.
