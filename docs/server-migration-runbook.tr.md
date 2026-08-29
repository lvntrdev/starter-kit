# Sunucu Taşıma Runbook'u

Zaten kurulu bir uygulamayı, şifreli ayarları veya 2FA verisini kaybetmeden yeni bir sunucuya taşımak için kopyala-yapıştır kontrol listesi. Buradaki herhangi bir adım tanıdık gelmiyorsa önce `docs/encryption.tr.md`'yi okuyun.

**En önemli tek kural:** mevcut `.env` dosyasını taşıyın, hedef sunucuda `php artisan key:generate` **çalıştırmayın**.

## Başlamadan önce

- [ ] Kaynak sunucuda tam bir veritabanı yedeği alın.
- [ ] Kaynak sunucunun `.env` dosyasının tam bir kopyasına sahip olduğunuzu doğrulayın — yeniden üretilmiş bir dosya değil, hafızadan doldurulmuş bir `.env.example` değil.
- [ ] Kaynak uygulamada aktif trafik varsa bir bakım penceresi planlayın.

## 1. Ortam dosyasını kopyalayın

- [ ] Kaynak sunucunun `.env` dosyasını hedef sunucuya olduğu gibi kopyalayın, **her ikisi dahil**:
  - `APP_KEY`
  - `DATA_ENCRYPTION_KEY` ve `DATA_ENCRYPTION_PREVIOUS_KEYS` (`DATA_ENCRYPTION_KEY` boş olsa bile — bu boşluk anlamlıdır ve "düzeltilmeden" olduğu gibi taşınmalıdır)
- [ ] `.env.example`'dan sıfır bir `.env` oluşturup değerleri elle yapıştırmayın. Gerçek dosyayı kopyalayın.

## 2. Hedef sunucuda ÇALIŞTIRMAYIN

- [ ] **`php artisan key:generate` çalıştırmayın.** Bu komut `APP_KEY`'in üzerine yazar. Bu kurulumda `DATA_ENCRYPTION_KEY` boşsa, birincil veri şifreleme anahtarı `APP_KEY`'DİR — üzerine yazmak her şifreli ayarı ve her kullanıcının 2FA sırrını, hatanın olduğu noktada hiçbir uyarı vermeden kalıcı olarak okunamaz yapar.
- [ ] Bu taşımanın bir parçası olarak hedef sunucuda `php artisan encryption:key` çalıştırmayın. Bu komut *yeni* bir anahtar üretir; rotasyon içindir, taşıma için değil. Taşıma mevcut anahtarı değiştirmeden taşır.
- [ ] Hedef sunucuda, saklamak istediğiniz veriye karşı `migrate:fresh`, `migrate:refresh`, `migrate:reset` veya `db:wipe` çalıştırmayın.

## 3. Uygulama kodunu deploy edin ve migration'ları çalıştırın

- [ ] Kod tabanını deploy edin (kaynak ile aynı sürüm, ya da hedeflediğiniz yükseltme sürümü).
- [ ] Bağımlılıkları kurun (deploy sürecinize göre `composer install --no-dev`, `npm ci && npm run build`).
- [ ] `php artisan migrate` çalıştırın (yalnızca şema — migration'lar hiçbir şifreleme anahtarına dokunmaz).
- [ ] Hedef sunucuda çoğaltılmış/stream edilmiş değil de sıfır bir veritabanı varsa "Başlamadan önce" adımındaki yedeği geri yükleyin.

## 4. Trafik göndermeden ÖNCE doğrulayın

Aşağıdaki ikisi de geçmeden hedef sunucuya DNS/load balancer trafiği yönlendirmeyin:

- [ ] `php artisan encryption:health`

  Kaynak sunucu zaten `encryption:rekey` çalıştırıp previous-keys listesini temizlediyse `safe-to-clear` (exit 0), bekleyen previous key'leri varsa `rekey-required` (exit 1) bekleyin — bu aşamada ikisi de sorun değil, ikisi de verinin okunabilir olduğu anlamına gelir. **`unreadable` (exit 2) veya `key-error` (exit 2) DUR demektir** — trafik göndermeyin, aşağıdaki kurtarma bölümüne bakın.

- [ ] `php artisan sk:doctor`

  Başarısız hiçbir kontrol olmadığını, özellikle şifrelemeyle ilgili herhangi bir kontrolü (`Data Encryption Key`) doğrulayın. Ortamınıza göre bir uyarı kabul edilebilir olabilir; bir başarısızlık kabul edilemez.

- [ ] Elle noktasal kontrol: admin panelinin Settings ekranını açın ve daha önce yapılandırılmış hassas bir değerin (örn. mail şifresi, yapılandırılmış bir storage secret'ı) hâlâ boş değil yapılandırılmış göründüğünü doğrulayın. 2FA açık bir kullanıcı olarak giriş yapın ve 2FA doğrulamasının hâlâ bir kodu kabul ettiğini doğrulayın.

Yalnızca her iki komut da geçtikten ve noktasal kontrol başarılı olduktan sonra trafiği hedef sunucuya kesin.

## 5. Kesimden sonra

- [ ] Hedef sunucunun kararlı olduğuna güvenene kadar kaynak sunucunun veritabanını ve `.env`'ini dokunulmadan, erişilebilir tutun (geri dönüş yolu).
- [ ] Yeni sunucuda veri şifreleme anahtarını rotasyona sokmayı planlıyorsanız, bunu ayrı, sonraki, bilinçli bir işlem olarak yapın — bkz. `docs/encryption.tr.md` → "Zaten benimsenmiş bir anahtarı rotasyona sokmak". Anahtar rotasyonunu sunucu taşımasıyla birleştirmeyin; önce taşımanın başarılı olduğunu doğrulayın.

## Kurtarma: eski anahtar gerçekten kayıp

`.env` doğru taşınmadıysa ve mevcut veriyi şifreleyen anahtar (kaynak sunucuda hangisi birincilse, `APP_KEY` ve/veya `DATA_ENCRYPTION_KEY`) gerçekten kayıpsa — hiçbir yerde yedeklenmemiş, eski `.env`'in hiçbir kopyasından kurtarılamıyorsa:

- **Bu kitteki hiçbir komut o veriyi kurtaramaz.** `encryption:health` `unreadable` veya `key-error` raporlar ve öyle kalır; bir onarım yolu yoktur.
- **Kurtarılamayan şeyler:**
  - Kayıp anahtar altında şifrelenmiş her hassas ayar (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) — hedef sunucu diğer açılardan sağlıklı hale geldikten sonra Settings ekranından **elle yeniden girilmelidir**.
  - Kayıp anahtar altında şifrelenmiş her kullanıcının 2FA sırrı ve kurtarma kodları — etkilenen her kullanıcı **iki faktörlü doğrulamayı kapatıp yeniden kaydolmalıdır**. Sır çözülemediği için mevcut doğrulama akışı üzerinden kendi kendine kurtaramazlar.
- **Etkilenmeyenler:** hâlâ elinizde olan bir anahtar altında şifrelenmiş her şey (örn. yalnızca `DATA_ENCRYPTION_PREVIOUS_KEYS` kaybolduysa ama mevcut birincil anahtar doğru taşındıysa, yalnızca hâlâ eski anahtarda kalan satırlar etkilenir — tam olarak hangileri olduğunu görmek için `encryption:health` çalıştırın).
- Bunu "hatayı yok etmek için" `DATA_ENCRYPTION_PREVIOUS_KEYS`'i temizleyerek veya `APP_KEY`/`DATA_ENCRYPTION_KEY`'i yeniden üreterek atlatmaya çalışmayın — bu veriyi geri getirmez ve gerçek anahtarı daha sonra bulma şansını da ortadan kaldırır.

## Bu dökümanda kullanılan yer tutucu değerler

Bu dökümanda veya `docs/encryption.tr.md`'de gösterilen herhangi bir anahtar (örn. bir `.env.example` bloğunda) bariz şekilde sahte bir yer tutucudur, örneğin `base64:REPLACE_ME_WITH_A_GENERATED_KEY=` — asla gerçek bir anahtar değildir. Gerçek bir `APP_KEY` veya `DATA_ENCRYPTION_KEY` değerini de bir ticket'a, sohbete veya commit mesajına yapıştırmayın; her ikisini de başka herhangi bir secret gibi işleyin.

## Ayrıca bakınız

- `docs/encryption.tr.md` — neyin şifrelendiği, anahtar çözümleme sözleşmesi, benimseme ve rotasyon prosedürleri.
- `docs/artisan-commands.tr.md` — `sk:doctor` dahil tam komut referansı.
