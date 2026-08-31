# Kimlik Doğrulama

Starter kit, web kimlik doğrulama için Laravel Fortify'ı ve API kimlik doğrulama için Passport'u birlikte kullanır.

## Web Kimlik Doğrulama

Hazır akışlar şunlardır:

- giriş
- kayıt
- şifremi unuttum
- şifre sıfırlama
- e-posta doğrulama
- şifre onayı
- iki faktör doğrulama ekranı

Bu ekranlar `resources/js/pages/Auth/` altında yer alır.

Turnstile ayarlardan etkinleştirildiğinde login, register ve forgot-password formları ortak `TurnstileWidget` bileşenini render eder ve `cf_turnstile_response` sunucu tarafında doğrulanır.

## Profil Güvenliği

Giriş yapmış kullanıcılar için ayrıca şu güvenlik araçları vardır:

- profil bilgisi güncelleme
- şifre güncelleme
- iki faktör ayarları
- recovery code görüntüleme ve yeniden üretme için şifre onayı
- tarayıcı oturum yönetimi
- avatar yükleme ve silme

Bu akışlar profil ekranı ve `routes/web/profile-route.php` içindeki ilişkili route'lar üzerinden çalışır.

## Parola Politikası

Parola politikası, **Ayarlar → Güvenlik → Parola Politikası** yönetici sekmesi tarafından yönetilir. Kurallar `auth.*` ayar anahtarları olarak saklanır ve `PasswordValidationRules` tarafından runtime'da uygulanır.

| Ayar anahtarı | Uyguladığı kural |
|---|---|
| `auth.password_min_length` | Minimum karakter sayısı (varsayılan: `8`) |
| `auth.password_require_mixed_case` | Büyük ve küçük harf zorunlu |
| `auth.password_require_numbers` | En az bir rakam zorunlu |
| `auth.password_require_symbols` | En az bir sembol zorunlu |

Her Fortify akışı aktif kuralları otomatik devralır — kayıt, şifre sıfırlama, şifre onayı ve profil şifre güncelleme. Yönetici kullanıcı oluşturma/güncelleme akışları da aynı kuralları uygular.

Politika değiştiğinde mevcut kullanıcıların parolaları geçersiz olmaz — yalnızca yeni gönderilen parolalar güncel kurala karşı ölçülür.

### Parola geçerlilik süresi

`auth.password_expiry_days` değerini `0`'dan büyük bir değere ayarlamak `EnsurePasswordNotExpired` middleware'ini etkinleştirir. `password_changed_at` zaman damgası yapılandırılan gün sayısından daha eski olan kimlik doğrulanmış kullanıcılar, parolalarını güncelleyene kadar adanmış, guest tarzı bir parola-süresi-doldu ekranına (`password.expired` rotası) yönlendirilir. `0` değeri (varsayılan) geçerlilik süresini tamamen devre dışı bırakır.

`password_changed_at`, her parola yazımında otomatik olarak güncellenir: kayıt, şifre sıfırlama, profil güncelleme ve yönetici kullanıcı oluşturma/güncelleme. Mevcut kullanıcılar, migration çalıştığında `now()` değeriyle geri doldurulduğundan, deploy tarihinden itibaren geçerlilik sürecini başlatırlar; anında süresi dolmuş duruma düşmezler.

## Çalışma Zamanı Kuralları

- pasif kullanıcılar web oturumu başlatamaz; Fortify login pipeline'ı status değeri `active` olmayan hesapları engeller
- login denemeleri IP ve email/IP kombinasyonlarına göre rate-limit edilir; `auth.login_throttle = '1'` (varsayılan, sert limiter) olduğunda Fortify rate limiter aktiftir; Ayarlar → Güvenlik'ten `'0'` yapıldığında limiter tamamen kalkmaz, daha gevşek bir `login-relaxed` tabanına geçilir — hiçbir admin ayarı web login'ini tamamen limitsiz bırakamaz. API auth route'ları bu ayardan bağımsız kendi sabit `throttle:5,1` middleware'ini taşır.
- iki faktör challenge akışının ayrı bir limiter'ı vardır
- iki faktör challenge'ı **tek kullanımlık** — yanlış kod, boş submit veya geçersiz recovery code challenge id'sini anında iptal eder; client yeni bir id almak için tekrar login olmak zorundadır
- forgot-password POST route'u, eşleşme anında dinamik olarak Turnstile middleware'i alır
- **API'de self-delete blokelidir.** `UserPolicy::delete` actor === target durumunda `false` dönüyor, yani `DELETE /api/v1/users/{self}` `users.delete` izni taşıyan kullanıcılar için bile 403 dönüyor. Desteklenen tek self-removal akışı Profile UI'daki password-confirmed Fortify yolu.

### Zaten açık olan bir oturumu kesmek

Yukarıdaki login anındaki kontrol, **zaten açık** bir oturuma ulaşamaz — aksi hâlde bir kullanıcıyı pasifleştiren yönetici, o kullanıcının çerezinin süresinin dolmasını beklemek zorunda kalırdı. Bu boşluğu iki parça kapatır.

**`EnsureUserIsActive` middleware'i.** `StarterKitServiceProvider::boot()` tarafından `sk.active` alias'ı olarak kaydedilir ve `web` ile `api` gruplarına eklenir; böylece mevcut bir kurulum `bootstrap/app.php` dosyasına dokunmadan `composer update` ile bunu alır. Her istekte kimliği doğrulanmış model üzerindeki `status` değerini okur ve değer operatörün deny-list'indeyse oturumu sonlandırır:

- **API / JSON isteği** → kitin dokümante edilmiş `ApiResponse` zarfında `403` (zarf middleware içinde kurulur; bu yüzden biçim `ApiExceptionHandler`'ın kayıtlı olmasına bağlı değildir).
- **Web isteği** → stateful guard'dan çıkış yapılır, session invalidate edilir, CSRF token yeniden üretilir ve login anındaki engelin kullandığı aynı `sk-auth.inactive` metniyle isimli `login` route'una yönlendirilir.
- **Kimlik bilgisi kesilemeyen web isteği** (`web` grubundan geçen bir token guard) veya `login` route'u olmayan bir uygulama → düz `403`. Yönlendirme döngüye girerdi, çünkü sonraki istek aynı kimlik bilgisiyle gelir.

**Bilinçli olarak fail-open'dır.** `users.status` kolonunu kitin kontrol etmediği uygulamalara da gider ve toplu kilitlenme, bir saniye önce pasifleştirilmiş bir hesaba fazladan bir istek servis etmekten çok daha kötüdür. Şu durumlarda istek geçirilir: listelenen hiçbir guard'da kullanıcı yoksa; listelenen bir guard `auth.guards` altında tanımlı değilse ya da çözümlenirken hata fırlatıyorsa; modelde `status` attribute'u yoksa; `status` null, bool veya string'e normalize olmayan başka bir değerse; ya da normalize edilmiş değer deny-list'te **değilse** — bilinmeyen string'ler dahil. Middleware asla "active değil, o hâlde engelle" çıkarımı yapmaz; yalnızca açıkça listelenmiş bir status'ü engelleyebilir.

**`RevokeUserAccessAction`.** Middleware yalnızca `web`/`api` gruplarından geçen isteklere etki eder. Bir kullanıcının normalize edilmiş status değeri deny-list'teki bir değere **geçtiğinde**, bu action ek olarak Passport access **ve** refresh token'larını, kullanılmamış authorization/device code'ları (bunlar aksi hâlde hâlâ yeni bir access token'a çevrilebilir) ve kullanıcının veritabanı session satırlarını iptal eder. Yalnızca gerçek bir geçişte çalışır — bir yıldır pasif olan bir kullanıcının adını düzenlemek hiçbir şey iptal etmez — ve çağırana asla hata fırlatmaz, çünkü status yazımı zaten commit edilmiştir.

**Yapılandırma** — `config/starter-kit.php`, `security` bloğu:

| Anahtar | Varsayılan | Ne yapar |
|---|---|---|
| `security.enforce_active_status` | `true` | Kill switch. `false` hem middleware'i hem de token iptalini devre dışı bırakır. |
| `security.active_status_denied` | `['inactive', 'banned']` | Oturumu sonlandıran tek status kümesi. Bilinçli olarak, gönderilen `userStatus` definition'ının ürettiği iki non-active değerle sınırlıdır. |
| `security.active_status_guards` | `['web', 'api']` | Her istekte incelenen guard'lar. |
| `security.csp_extra_origins` | `[]` | Kitin Content-Security-Policy başlığına eklenen ek origin'ler. |

> `mergeConfigFrom` yalnızca **üst seviye** anahtarları birleştirir. Bu sürümden önce yayınlanmış bir `config/starter-kit.php` hiç `security` anahtarı taşımaz ve vendor bloğunu bütün olarak devralır; **kısmi** bir `security` dizisi taşıyan bir dosya ise yazmadığı her iç anahtar için vendor bloğunun yerine geçer. Bu yüzden middleware, gönderilen literal değerleri birebir tekrarlayan sınıf sabitlerine (`EnsureUserIsActive::ENFORCE_DEFAULT` / `::DENIED_DEFAULT` / `::GUARDS_DEFAULT`) düşer; her iki kitle de aynı değerleri çözer.

## API Kimlik Doğrulama

API tarafında Passport kullanılır:

- personal access token desteği
- `POST /api/v1/auth/register` ve `POST /api/v1/auth/login` public'tir ve throttle uygulanır
- `POST /api/v1/auth/two-factor-challenge` public'tir ve throttle uygulanır
- `POST /api/v1/auth/logout` ve `GET /api/v1/auth/me` için `auth:api` gerekir

### API Auth Akışı

- `register`, yalnızca email verification kapalıysa `201` ile `{ user, token }` döner
- email verification açıkken `register`, token vermeden `201` ile `{ user, requires_verification: true }` döner
- `login`, `{ user, token }`, `{ requires_verification: true }` veya `{ requires_two_factor: true, challenge }` şekillerinden birini dönebilir
- `two-factor-challenge`, API 2FA akışını `code` veya `recovery_code` ile tamamlar ve başarı durumunda `{ user, token }` döner
- istemciler, her başarılı auth yanıtında token beklemek yerine `requires_verification` ve `requires_two_factor` alanlarına göre dallanmalıdır

## Passport Yapılandırması

Token ömürleri ve scope kataloğu `config/starter-kit.php` dosyasında `passport` anahtarı altında tanımlanır ve boot sırasında `StarterKitServiceProvider` tarafından uygulanır (`laravel/passport` kurulu değilse no-op'tur).

| Config anahtarı (`passport.…`) | Env değişkeni | Varsayılan | Etkisi |
|---|---|---|---|
| `provider` | `STARTER_KIT_PASSPORT_PROVIDER` | `users` | Otomatik kaydedilen `api` guard'ının arkasındaki auth provider. Guard yalnızca uygulama zaten `auth.guards.api` tanımlamamışsa sentezlenir — kendi tanımladığınız bir custom guard asla ezilmez. |
| `access_token_minutes` | `PASSPORT_TOKEN_MINUTES` | `60` | Access token TTL'i, `Passport::tokensExpireIn()` üzerinden uygulanır. |
| `refresh_token_days` | `PASSPORT_REFRESH_TOKEN_DAYS` | `14` | Refresh token TTL'i, `Passport::refreshTokensExpireIn()` üzerinden uygulanır. |
| `personal_token_days` | `PASSPORT_PERSONAL_TOKEN_DAYS` | `30` | Personal Access Token TTL'i, `Passport::personalAccessTokensExpireIn()` üzerinden uygulanır. |
| `scopes` | — | 5 örnek scope (`users.read`, `users.write`, `files.read`, `files.write`, `admin`) | `Passport::tokensCan()` ile kaydedilen scope kataloğu. Aynı zamanda `/admin/api-tokens` üzerinden bir PAT oluşturulurken `StoreApiTokenRequest`'in doğruladığı allow-list'tir — bu katalog dışında bir scope istemek, Passport'a hiç ulaşmadan validation hatasıyla reddedilir. Katalogda bulunsa bile literal `*` allow-list'ten çıkarılır, yani UI üzerinden wildcard scope'lu bir PAT asla oluşturulamaz. |
| `default_scopes` | — | `[]` (boş) | `Passport::setDefaultScope()` ile uygulanan scope(lar). Yalnızca `scopes` doluyken etkilidir. |

Set edildiğinde (null olmayan, boş olmayan bir string) hâlâ öncelikli olan iki legacy env değişkeni vardır:

- `PASSPORT_TOKEN_DAYS` (gün) — `access_token_minutes`'ı ezer; değer dakikaya çevrilmek için `24 * 60` ile çarpılır.
- `PASSPORT_PERSONAL_TOKEN_MONTHS` (ay) — `personal_token_days`'i ezer; değer güne çevrilmek için `30` ile çarpılır.

İkisi de sırasıyla `passport.access_token_days` / `passport.personal_token_months` config anahtarlarına eşlenir; bu anahtarların varsayılanı `null`'dur — set edilmedikleri sürece yukarıdaki dakika/gün anahtarları geçerli olur.

**Scope enforcement opt-in'dir.** `passport.scopes`'u doldurmak tek başına hiçbir şeyi kısıtlamaz — yalnızca katalog ve (isteğe bağlı) default'u kaydeder. Bir route'u gerçekten kısıtlamak için Passport'un kendi `scope`/`scopes` middleware'ini route'a eklemeniz gerekir (örn. `->middleware('scope:users.read')`). `scopes`'u boş bırakmak Passport'un implicit `*` scope'unu korur, böylece mevcut istemciler ve token'lar değişmeden çalışmaya devam eder. `/admin/api-clients` üzerinden oluşturulan OAuth2 istemcilerinin hiç scope taşımadığını unutmayın (kaldırıldı — bkz. [API İstemcileri ve Token'lar](./api-clients.tr.md)); yukarıdaki scope kataloğu yalnızca Personal Access Token'lar için geçerlidir.

## API İstemcileri ve Token'lar

Admin paneli, Passport OAuth2 istemcilerini ve Personal Access Token'ları (PAT) yönetmek için bir arayüz sunar:

- `/admin/api-clients` — OAuth2 istemcilerini listele, oluştur, güncelle ve sil
- `/admin/api-tokens` — Personal Access Token'ları yönet

İstemci secret'ları ve PAT değerleri yalnızca oluşturma anında dismiss edilemeyen bir modal içinde bir kez gösterilir; plaintext olarak hiçbir zaman saklanmaz. Ayrıntılı belge için bkz. [API İstemcileri ve Token'lar](./api-clients.tr.md).

## Notlar

- tarayıcı tarafındaki auth deneyimi için Fortify kullanın
- harici veya token tabanlı API tüketicileri için Passport kullanın
- aynı user model kullanılsa bile web ve API auth sorumluluklarını ayrı düşünün
