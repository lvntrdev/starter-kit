# API

Starter kit, `/api/v1` altında versiyonlanmış bir JSON API sunar. Bu sözleşmeyi rota dosyalarını elle okumak yerine, her zaman güncel ve gezilebilir bir görünüm için `/api-dock` adresindeki api-dock panelinden (seed edilen `api-docs.read` izniyle korunur) inceleyin.

## Yanıt Standardı

Tüm API yanıtları ortak bir zarf yapısı kullanır:

```json
{
    "success": true,
    "status": 200,
    "message": "Operation successful.",
    "data": {},
    "meta": {},
    "trace_id": "uuid"
}
```

Şunları kullanın:

- `to_api()`
- `ApiResponse`
- `ApiException`

Normal paket tarzı endpoint'lerde doğrudan `response()->json()` kullanmayın.

## Route Dosya Yapısı

Tüm API route'ları `/api/v1` altında yer alır ve genel `throttle:api` middleware'i uygulanır. `routes/api/` dizinindeki route dosyaları otomatik yüklenir ve üç katmana ayrılır:

**Public** (`routes/api/public-api.php`) — kimlik doğrulama gerekmez. Register, login ve API two-factor challenge endpoint'lerinde ek olarak `throttle:5,1` (dakikada 5 istek) uygulanır.

**Yalnızca auth** (`auth-route.php`, `service-route.php`) — `auth:api` ile sarılır. Permission kontrolü yapılmaz.

**Permission korumalı** (`user-route.php` dahil diğer tüm route dosyaları) — `['auth:api', 'check.permission']` ile sarılır. `check.permission` middleware'i beklenen permission'ı route adına göre çözer ve authenticated kullanıcı üzerinde doğrular.

## Auth Endpoint'leri

Public (token gerekmez):

- `POST /api/v1/auth/register` — dakikada 5 istekle sınırlı
- `POST /api/v1/auth/login` — dakikada 5 istekle sınırlı
- `POST /api/v1/auth/two-factor-challenge` — dakikada 5 istekle sınırlı

Korumalı (`auth:api`):

- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

## Service Endpoint'leri

Korumalı (`auth:api`):

- `GET /api/v1/definitions`

## Resource Endpoint'leri

Korumalı (`auth:api` + `check.permission`):

- `Route::apiResource('users', UserController::class)` — `routes/api/user-route.php` içinde tanımlı. `index` action'ı, admin panelinin kullandığı aynı `UserDatatableQuery` query class'ına delegasyon yapıyor; role-hiyerarşi filtresi (`system_admin` olmayan bir aktör üst-rank kullanıcıları göremez) iki yüzeyde de aynı şekilde uygulanıyor. Bkz. [roles-permissions.tr.md](./roles-permissions.tr.md#user-yönetiminde-rol-hiyerarşisi).

## Kimlik Doğrulama Modeli

API koruması Passport ve `auth:api` guard'ı ile sağlanır.

Başarılı auth yanıtlarının artık her zaman token içerdiği varsayılmamalıdır:

- `register`, `{ user, requires_verification: true }` dönebilir
- `login`, `{ requires_verification: true }` veya `{ requires_two_factor: true, challenge }` dönebilir
- `two-factor-challenge`, üretilen `challenge` ile birlikte `code` veya `recovery_code` alıp `{ user, token }` döner

## Request Tracing

Her yanıtın zarfında bir `trace_id` ve yanıtın header'ında bir `X-Request-ID` bulunur.

- **`trace_id` her zaman sunucu tarafında üretilir** — `Str::uuid()` ile. İsteği uygulama log'larında tekil olarak tanımlar; bir hata raporu açarken destek ekibine bu id'yi iletin.
- **Client'ın gönderdiği `X-Request-ID` header'ı yalnızca correlation metadata olarak** — ve ancak `[A-Za-z0-9._-]{1,128}` ile eşleşirse kabul edilir. Sanitize edilmiş değer log'a `client_request_id` olarak yazılır; charset dışında veya 128 karakterden uzun değerler sessizce düşürülür. Yanıt header'ı her zaman sunucu tarafında üretilen id'yi taşır, client'ın gönderdiğini değil.

## CORS

Starter kit, `fruitcake/laravel-cors` / Laravel'in bundled CORS middleware'ini `config/cors.php` ile shipping ediyor. Default `max_age` değeri `7200` (2 saat); tarayıcılar `OPTIONS` preflight yanıtını cache'liyor ve SPA / mobile client'lar her mutating çağrıda handshake ödemiyor. Production'a geçmeden önce `allowed_origins`, `supports_credentials` ve `max_age` değerlerini deployment'ınıza göre ayarlayın.

## Hata Yönetimi

Validation, authentication, authorization, not found ve benzeri beklenen hatalar API exception katmanı üzerinden normalize edilir. Handle edilmemiş 5xx hatalar, `APP_DEBUG`'dan bağımsız olarak generic bir `A server error occurred.` mesajı döner — exception detayı yalnızca log'larda ve `APP_DEBUG=true` iken zarfın yanında ek bir `debug` block'unda bulunur.
