# Lvntr Starter Kit

![Tests](https://img.shields.io/badge/tests-passing-22c55e?style=flat-square)
![License](https://img.shields.io/badge/license-PolyForm--Noncommercial%201.0.0-f59e0b?style=flat-square)
![Packagist Sürüm](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

Lvntr Starter Kit aktif olarak geliştiriliyor; her yeni sürüm onu daha olgun, daha kapsamlı ve admin odaklı bir Laravel platformuna dönüştürüyor.

> **Web Sitesi & Dökümantasyon:** [starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)
> Detaylı kullanım kılavuzları, örnekler ve referans dökümanlarının yer aldığı resmi web sitesi.

**Laravel 13**, **Inertia.js v3**, **Vue 3**, **PrimeVue 4** ve **Tailwind CSS 4** ile oluşturulmuş, tam donanımlı bir Laravel admin panel paketi. DDD (Domain-Driven Design) mimarisi ile rol tabanlı yetkilendirme, aktivite kaydı, ayarlar yönetimi ve daha fazlasını içerir.

## Özellikler

- **DDD Mimarisi** — Action'lar, DTO'lar, Query'ler, Event'ler, Listener'lar
- **Rol ve Yetki Yönetimi** — Spatie Permission ile dinamik kaynak bazlı yetkiler
- **Kullanıcı Yönetimi** — Avatar yükleme, soft delete, 2FA desteği ile CRUD
- **Aktivite Kaydı** — Spatie Activity Log ile gözatılabilir admin arayüzü
- **Ayarlar Paneli** — Genel, Kimlik Doğrulama, Mail, Depolama ayarları veritabanında saklanır
- **OAuth2 API** — Laravel Passport ile kişisel erişim tokenları ve cihaz yetkilendirme
- **Domain İskelet Oluşturucu** — `make:sk-domain` komutu ile interaktif tam DDD katmanı
- **FormBuilder / DatatableBuilder / TabBuilder** — Yeniden kullanılabilir Vue bileşen oluşturucuları
- **Çoklu Dil Desteği** — Çeviri dosyaları dahil, kolayca genişletilebilir
- **API Yanıt Oluşturucu** — Akıcı, tutarlı API yanıtları ve sayfalama desteği
- **Güvenlik Başlık Middleware** — X-Frame-Options, HSTS, CSP ve daha fazlası

## Teknoloji Yığını

### Backend (PHP / Composer)

| Paket                    | Amaç                                                                          |
| ------------------------ | ----------------------------------------------------------------------------- |
| **Laravel 13**           | Çekirdek framework (constraint: `^13.0`)                                      |
| **Inertia.js v3**        | Sunucu tabanlı SPA — backend ile frontend arasında API katmanına gerek yok    |
| **Laravel Fortify**      | Kimlik doğrulama altyapısı (giriş, kayıt, 2FA, şifre sıfırlama)               |
| **Laravel Passport**     | OAuth2 API kimlik doğrulaması (kişisel erişim tokenları, cihaz yetkilendirme) |
| **Laravel Wayfinder**    | TypeScript için tip-güvenli rota oluşturma                                    |
| **Spatie Permission**    | Dinamik kaynak bazlı yetkilerle rol ve yetki yönetimi                         |
| **Spatie Activity Log**  | Gözatılabilir admin arayüzü ile model aktivite kaydı                          |
| **Spatie Media Library** | Dosya yüklemeleri ve medya koleksiyonları (avatarlar, ekler)                  |
| **Spatie Query Builder** | Sorgu dizisi üzerinden filtreleme, sıralama ve ilişki dahil etme              |
| **Spatie Translatable**  | Çoklu dil model özellikleri (JSON tabanlı)                                    |

### Frontend (Node / npm)

| Paket                | Amaç                                                         |
| -------------------- | ------------------------------------------------------------ |
| **Vue 3**            | Reaktif UI framework                                         |
| **PrimeVue 4**       | UI bileşen kütüphanesi (DataTable, Dialog, Toast, Menu, vb.) |
| **Tailwind CSS 4**   | Utility-first CSS framework                                  |
| **Inertia.js Vue 3** | Inertia SPA için istemci tarafı adaptörü                     |
| **VueUse**           | Vue composition yardımcı araçları koleksiyonu                |
| **laravel-vue-i18n** | Laravel çeviri dosyalarını doğrudan Vue'da kullanma          |

### Geliştirme Araçları

| Araç                    | Amaç                                   |
| ----------------------- | -------------------------------------- |
| **Vite**                | HMR ile frontend derleme aracı         |
| **TypeScript**          | Frontend kodu için tip güvenliği       |
| **ESLint + Prettier**   | Kod linting ve formatlama              |
| **Vitest**              | Vue bileşenleri için birim testi       |
| **Husky + lint-staged** | Kod kalitesi için pre-commit hook'ları |
| **Commitizen**          | Konvansiyonel commit mesajları         |

## Gereksinimler

- PHP 8.3+
- Laravel 13
- Node.js 18+
- MySQL / PostgreSQL / SQLite

## Kurulum

### 1. Paketi ekleyin

```bash
composer require lvntr/laravel-starter-kit:^13.0
```

### 2. Kurulum komutunu çalıştırın

```bash
php artisan sk:install
```

Bu interaktif sihirbaz şunları yapacak:

1. Tüm uygulama iskelesini yayınlar (Controller'lar, Model'ler, Route'lar, Vue sayfaları, vb.)
2. Paket yapılandırma dosyasını yayınlar
3. Veritabanı migration'larını çalıştırır
4. Seeder'ları çalıştırır (Roller, Yetkiler, Tanımlar, Ayarlar)
5. Passport şifreleme anahtarlarını oluşturur
6. Varsayılan admin kullanıcısı oluşturur
7. npm bağımlılıklarını yükler ve frontend'i derler

**Etkileşimsiz mod (CI/CD):**

```bash
php artisan sk:install --no-interaction
```

**Mevcut dosyaların üzerine yaz:**

```bash
php artisan sk:install --force
```

### 3. `.env` dosyanızı yapılandırın

```env
APP_NAME="Uygulamam"
APP_URL=https://uygulamam.test

DB_CONNECTION=mysql
DB_DATABASE=uygulamam
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Admin paneline erişin

Tarayıcınızı açıp uygulama URL'nize gidin. Kurulum sonrası gösterilen admin bilgileriyle giriş yapın (varsayılan: `admin@demo.com` / `password`).

## Güncelleme

Paketin yeni bir sürümü yayınlandığında:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

Güncelleme komutu dosyaları güvenli şekilde güncellemek için **hash tabanlı izleme sistemi** kullanır:

- **Çekirdek dosyalar** (BaseAction, BaseDTO, Trait'ler, Middleware, helper'lar) her zaman güncellenir
- **Kullanıcı tarafından değiştirilebilir dosyalar** (Controller'lar, Sayfalar, Route'lar) sadece siz değiştirmemişseniz güncellenir
- **Yeni dosyalar** paketten otomatik olarak eklenir
- **Yeni migration'lar** tespit edilir ve isteğe bağlı olarak çalıştırılır

**Uygulamadan önce değişiklikleri önizleyin:**

```bash
php artisan sk:update --dry-run
```

**Her şeyi zorla güncelle (değişikliklerinizin üzerine yazar):**

```bash
php artisan sk:update --force
```

## İsteğe Bağlı Varlıkları Yayınlama

Paket, Vue bileşenlerini, dil dosyalarını ve yapılandırmayı varsayılan olarak paketin içinde tutar. Özelleştirmeniz gerekiyorsa projenize yayınlayın:

```bash
# İnteraktif seçim
php artisan sk:publish

# Vue bileşenlerini yayınla (FormBuilder, DatatableBuilder, vb.)
php artisan sk:publish --tag=components

# Dil dosyalarını yayınla
php artisan sk:publish --tag=lang

# Yapılandırma dosyasını yayınla
php artisan sk:publish --tag=config
```

## Mevcut Komutlar

| Komut              | Açıklama                                                        |
| ------------------ | --------------------------------------------------------------- |
| `sk:install`       | Tam kurulum sihirbazı                                           |
| `sk:update`        | Kullanıcı değişikliklerini koruyarak paket dosyalarını güncelle |
| `sk:upgrade`       | Önceki Laravel sürümünden yükseltme                             |
| `sk:publish`       | Özelleştirme için isteğe bağlı varlıkları yayınla               |
| `site:install`     | Veritabanını sıfırla ve varsayılan verilerle yeniden kur        |
| `make:sk-domain`   | İnteraktif olarak eksiksiz bir DDD domain'i oluştur             |
| `remove:sk-domain` | Bir domain'i ve tüm dosyalarını kaldır                          |
| `env:sync`         | .env anahtarlarını .env.example ile senkronize et               |

### Domain İskelet Oluşturma

Tüm DDD katmanlarıyla yeni bir domain oluşturun:

```bash
# İnteraktif mod
php artisan make:sk-domain

# Seçeneklerle
php artisan make:sk-domain Product --fields="name:string,price:decimal" --admin --api --events --vue=full
```

Bu komut şunları oluşturur: Model, Migration, Factory, DTO, Action'lar, Event'ler, Listener'lar, Controller'lar, FormRequest'ler, Route'lar ve Vue sayfaları.

Bir domain'i kaldırın:

```bash
php artisan remove:sk-domain Product
```

## Mimari

### Paket Yapısı

```
lvntr/laravel-starter-kit/
├── src/                          # Çekirdek paket kodu (asla yayınlanmaz)
│   ├── StarterKitServiceProvider.php
│   ├── Console/Commands/         # sk:install, sk:update, make:sk-domain, vb.
│   ├── Domain/Shared/            # BaseAction, BaseDTO, ActionPipeline
│   ├── Enums/                    # PermissionEnum
│   ├── Http/Middleware/          # CheckResourcePermission, SecurityHeaders
│   ├── Http/Responses/           # ApiResponse oluşturucu
│   ├── Support/                  # Paket destek sınıfları
│   ├── Traits/                   # HasActivityLogging, HasMediaCollections
│   └── helpers.php               # to_api(), format_date()
├── resources/
│   ├── js/components/            # Vue bileşenleri (isteğe bağlı yayınlanabilir)
│   └── lang/                     # Çeviri dosyaları (isteğe bağlı yayınlanabilir)
├── stubs/                        # Kurulumda uygulamaya kopyalanır
│   ├── app/                      # Controller'lar, Model'ler, Domain, Provider'lar, Enum'lar
│   ├── config/                   # permission-resources.php, settings.php
│   ├── database/                 # Migration'lar, Seeder'lar, Factory'ler
│   ├── routes/                   # Web ve API route'ları
│   ├── resources/js/             # Vue sayfaları, Layout'lar, Composable'lar, Tema
│   └── bootstrap/                # app.php, providers.php
└── config/
    └── starter-kit.php           # Paket yapılandırması
```

### Uygulama Yapısı (kurulumdan sonra)

```
app/
├── Domain/                       # DDD iş mantığı
│   ├── User/                     # Action'lar, DTO'lar, Query'ler, Event'ler, Listener'lar
│   ├── Role/
│   ├── Auth/
│   ├── Setting/
│   ├── ActivityLog/
│   └── Shared/                   # Temel sınıflar (paket tarafından güncellenir)
├── Http/
│   ├── Controllers/Admin/        # Admin panel controller'ları
│   ├── Controllers/Api/          # REST API controller'ları
│   └── Middleware/
├── Models/
├── Enums/
└── Providers/
```

### Güncelleme Stratejisi

| Dosya Kategorisi                                    | `sk:update` ile Davranış                     |
| --------------------------------------------------- | -------------------------------------------- |
| `Domain/Shared/`, Trait'ler, Middleware, helper'lar | Her zaman güncellenir                        |
| Controller'lar, Model'ler, Sayfalar, Route'lar      | Sadece kullanıcı değiştirmemişse güncellenir |
| Kullanıcının kendi domain'leri                      | Asla dokunulmaz                              |
| Paketten gelen yeni dosyalar                        | Otomatik olarak eklenir                      |

## Paket Bileşenlerini Kullanma

### Vue Bileşenleri (yayınlamadan)

Bileşenler paketten otomatik olarak çözümlenir. Vue dosyalarınızda kullanın:

```vue
<template>
    <SkForm :config="formConfig" />
    <SkDatatable :config="tableConfig" />
    <SkTabs :config="tabConfig" />
</template>
```

### Çeviriler

```php
// Paket ad alanından
__('starter-kit::admin.menu.dashboard')
__('starter-kit::message.created')
```

### Temel Sınıflar

```php
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;
use Lvntr\StarterKit\Enums\PermissionEnum;
use Lvntr\StarterKit\Traits\HasActivityLogging;
```

## Lisans

[PolyForm Noncommercial 1.0.0](./LICENSE)
