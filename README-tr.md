# Lvntr Starter Kit

![Tests](https://img.shields.io/badge/tests-passing-22c55e?style=flat-square)
![License](https://img.shields.io/badge/license-PolyForm--Noncommercial%201.0.0-f59e0b?style=flat-square)
![Packagist Surum](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

> ⚠️ **End-of-Life Bildirimi:** Bu branch (`1.x`) **Laravel 12** icin gelistiriliyor. `v12.0.63` itibariyla **sadece bakim modundadir** — yeni ozellik veya hata duzeltmesi yayinlanmayacak. `lvntr/laravel-starter-kit:^12.0` ile kurulmus mevcut projeler calismaya devam eder; gelecek guncellemeler icin projenizi **Laravel 13**'e yukseltip `lvntr/laravel-starter-kit:^13.0` kullanmanizi oneririz (bkz. [`main` branch](https://github.com/lvntrdev/laravel-starter-kit)).

Lvntr Starter Kit aktif olarak gelistiriliyor; her yeni surum onu daha olgun, daha kapsamli ve admin odakli bir Laravel platformuna donusturuyor.

Detayli kullanim dokumanlari: [kit-docs.lvntr.dev](https://kit-docs.lvntr.dev/)

**Laravel 12**, **Inertia.js v2**, **Vue 3**, **PrimeVue 4** ve **Tailwind CSS 4** ile olusturulmus, tam donanimli bir Laravel admin panel paketi. DDD (Domain-Driven Design) mimarisi ile rol tabanli yetkilendirme, aktivite kaydi, ayarlar yonetimi ve daha fazlasini icerir.

## Ozellikler

- **DDD Mimarisi** — Action'lar, DTO'lar, Query'ler, Event'ler, Listener'lar
- **Rol ve Yetki Yonetimi** — Spatie Permission ile dinamik kaynak bazli yetkiler
- **Kullanici Yonetimi** — Avatar yukleme, soft delete, 2FA destegi ile CRUD
- **Aktivite Kaydi** — Spatie Activity Log ile gozatilabilir admin arayuzu
- **Ayarlar Paneli** — Genel, Kimlik Dogrulama, Mail, Depolama ayarlari veritabaninda saklanir
- **OAuth2 API** — Laravel Passport ile kisisel erisim tokenlari ve cihaz yetkilendirme
- **Domain Iskelet Olusturucu** — `make:sk-domain` komutu ile interaktif tam DDD katmani
- **FormBuilder / DatatableBuilder / TabBuilder** — Yeniden kullanilabilir Vue bilesen olusturuculari
- **Coklu Dil Destegi** — Ceviri dosyalari dahil, kolayca genisletilebilir
- **API Yanit Olusturucu** — Akici, tutarli API yanitlari ve sayfalama destegi
- **Guvenlik Baslik Middleware** — X-Frame-Options, HSTS, CSP ve daha fazlasi

## Teknoloji Yigini

### Backend (PHP / Composer)

| Paket                    | Amac                                                                          |
| ------------------------ | ----------------------------------------------------------------------------- |
| **Laravel 12**           | Cekirdek framework                                                            |
| **Inertia.js v2**        | Sunucu tabanli SPA — backend ile frontend arasinda API katmanina gerek yok    |
| **Laravel Fortify**      | Kimlik dogrulama altyapisi (giris, kayit, 2FA, sifre sifirlama)               |
| **Laravel Passport**     | OAuth2 API kimlik dogrulamasi (kisisel erisim tokenlari, cihaz yetkilendirme) |
| **Laravel Wayfinder**    | TypeScript icin tip-guvenli rota olusturma                                    |
| **Spatie Permission**    | Dinamik kaynak bazli yetkilerle rol ve yetki yonetimi                         |
| **Spatie Activity Log**  | Gozatilabilir admin arayuzu ile model aktivite kaydi                          |
| **Spatie Media Library** | Dosya yuklemeleri ve medya koleksiyonlari (avatarlar, ekler)                  |
| **Spatie Query Builder** | Sorgu dizisi uzerinden filtreleme, siralama ve iliski dahil etme              |
| **Spatie Translatable**  | Coklu dil model ozellikleri (JSON tabanli)                                    |

### Frontend (Node / npm)

| Paket                | Amac                                                         |
| -------------------- | ------------------------------------------------------------ |
| **Vue 3**            | Reaktif UI framework                                         |
| **PrimeVue 4**       | UI bilesen kutuphanesi (DataTable, Dialog, Toast, Menu, vb.) |
| **Tailwind CSS 4**   | Utility-first CSS framework                                  |
| **Inertia.js Vue 3** | Inertia SPA icin istemci tarafi adaptoru                     |
| **VueUse**           | Vue composition yardimci araclari koleksiyonu                |
| **laravel-vue-i18n** | Laravel ceviri dosyalarini dogrudan Vue'da kullanma          |

### Gelistirme Araclari

| Arac                    | Amac                                   |
| ----------------------- | -------------------------------------- |
| **Vite**                | HMR ile frontend derleme araci         |
| **TypeScript**          | Frontend kodu icin tip guvenligi       |
| **ESLint + Prettier**   | Kod linting ve formatlama              |
| **Vitest**              | Vue bilesenleri icin birim testi       |
| **Husky + lint-staged** | Kod kalitesi icin pre-commit hook'lari |
| **Commitizen**          | Konvansiyonel commit mesajlari         |

## Gereksinimler

- PHP 8.2+
- Laravel 12
- Node.js 18+
- MySQL / PostgreSQL / SQLite

## Kurulum

### 1. Paketi ekleyin

```bash
composer require lvntr/laravel-starter-kit
```

### 2. Kurulum komutunu calistirin

```bash
php artisan sk:install
```

Bu interaktif sihirbaz sunlari yapacak:

1. Tum uygulama iskelesini yayinlar (Controller'lar, Model'ler, Route'lar, Vue sayfalari, vb.)
2. Paket yapilandirma dosyasini yayinlar
3. Veritabani migration'larini calistirir
4. Seeder'lari calistirir (Roller, Yetkiler, Tanimlar, Ayarlar)
5. Passport sifreleme anahtarlarini olusturur
6. Varsayilan admin kullanicisi olusturur
7. npm bagimliliklerini yukler ve frontend'i derler

**Etkilesimsiz mod (CI/CD):**

```bash
php artisan sk:install --no-interaction
```

**Mevcut dosyalarin uzerine yaz:**

```bash
php artisan sk:install --force
```

### 3. `.env` dosyanizi yapilandirin

```env
APP_NAME="Uygulamam"
APP_URL=https://uygulamam.test

DB_CONNECTION=mysql
DB_DATABASE=uygulamam
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Admin paneline erisin

Tarayicinizi acip uygulama URL'nize gidin. Kurulum sonrasi gosterilen admin bilgileriyle giris yapin (varsayilan: `admin@demo.com` / `password`).

## Guncelleme

Paketin yeni bir surumu yayinlandiginda:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

Guncelleme komutu dosyalari guvenli sekilde guncellemek icin **hash tabanli izleme sistemi** kullanir:

- **Cekirdek dosyalar** (BaseAction, BaseDTO, Trait'ler, Middleware, helper'lar) her zaman guncellenir
- **Kullanici tarafindan degistirilebilir dosyalar** (Controller'lar, Sayfalar, Route'lar) sadece siz degistirmemisseniz guncellenir
- **Yeni dosyalar** paketten otomatik olarak eklenir
- **Yeni migration'lar** tespit edilir ve istege bagli olarak calistirilir

**Uygulamadan once degisiklikleri onizleyin:**

```bash
php artisan sk:update --dry-run
```

**Her seyi zorla guncelle (degisikliklerinizin uzerine yazar):**

```bash
php artisan sk:update --force
```

## Istege Bagli Varliklari Yayinlama

Paket, Vue bilesenlerini, dil dosyalarini ve yapilandirmayi varsayilan olarak paketin icinde tutar. Ozellestirmeniz gerekiyorsa projenize yayinlayin:

```bash
# Interaktif secim
php artisan sk:publish

# Vue bilesenlerini yayinla (FormBuilder, DatatableBuilder, vb.)
php artisan sk:publish --tag=components

# Dil dosyalarini yayinla
php artisan sk:publish --tag=lang

# Yapilandirma dosyasini yayinla
php artisan sk:publish --tag=config
```

## Mevcut Komutlar

| Komut              | Aciklama                                                        |
| ------------------ | --------------------------------------------------------------- |
| `sk:install`       | Tam kurulum sihirbazi                                           |
| `sk:update`        | Kullanici degisikliklerini koruyarak paket dosyalarini guncelle |
| `sk:publish`       | Ozellestirme icin istege bagli varliklari yayinla               |
| `make:sk-domain`   | Interaktif olarak eksiksiz bir DDD domain'i olustur             |
| `remove:sk-domain` | Bir domain'i ve tum dosyalarini kaldir                          |
| `env:sync`         | .env anahtarlarini .env.example ile senkronize et               |

### Domain Iskelet Olusturma

Tum DDD katmanlariyla yeni bir domain olusturun:

```bash
# Interaktif mod
php artisan make:sk-domain

# Seceneklerle
php artisan make:sk-domain Product --fields="name:string,price:decimal" --admin --api --events --vue=full
```

Bu komut sunlari olusturur: Model, Migration, Factory, DTO, Action'lar, Event'ler, Listener'lar, Controller'lar, FormRequest'ler, Route'lar ve Vue sayfalari.

Bir domain'i kaldirin:

```bash
php artisan remove:sk-domain Product
```

## Mimari

### Paket Yapisi

```
lvntr/laravel-starter-kit/
├── src/                          # Cekirdek paket kodu (asla yayinlanmaz)
│   ├── StarterKitServiceProvider.php
│   ├── Console/Commands/         # sk:install, sk:update, make:sk-domain, vb.
│   ├── Domain/Shared/            # BaseAction, BaseDTO, ActionPipeline
│   ├── Enums/                    # PermissionEnum
│   ├── Http/Middleware/          # CheckResourcePermission, SecurityHeaders
│   ├── Http/Responses/           # ApiResponse olusturucu
│   ├── Support/                  # Paket destek siniflari
│   ├── Traits/                   # HasActivityLogging, HasMediaCollections
│   └── helpers.php               # to_api(), format_date()
├── resources/
│   ├── js/components/            # Vue bilesenleri (istege bagli yayinlanabilir)
│   └── lang/                     # Ceviri dosyalari (istege bagli yayinlanabilir)
├── stubs/                        # Kurulumda uygulamaya kopyalanir
│   ├── app/                      # Controller'lar, Model'ler, Domain, Provider'lar, Enum'lar
│   ├── config/                   # permission-resources.php, settings.php
│   ├── database/                 # Migration'lar, Seeder'lar, Factory'ler
│   ├── routes/                   # Web ve API route'lari
│   ├── resources/js/             # Vue sayfalari, Layout'lar, Composable'lar, Tema
│   └── bootstrap/                # app.php, providers.php
└── config/
    └── starter-kit.php           # Paket yapilandirmasi
```

### Uygulama Yapisi (kurulumdan sonra)

```
app/
├── Domain/                       # DDD is mantigi
│   ├── User/                     # Action'lar, DTO'lar, Query'ler, Event'ler, Listener'lar
│   ├── Role/
│   ├── Auth/
│   ├── Setting/
│   ├── ActivityLog/
│   └── Shared/                   # Temel siniflar (paket tarafindan guncellenir)
├── Http/
│   ├── Controllers/Admin/        # Admin panel controller'lari
│   ├── Controllers/Api/          # REST API controller'lari
│   └── Middleware/
├── Models/
├── Enums/
└── Providers/
```

### Guncelleme Stratejisi

| Dosya Kategorisi                                    | `sk:update` ile Davranis                     |
| --------------------------------------------------- | -------------------------------------------- |
| `Domain/Shared/`, Trait'ler, Middleware, helper'lar | Her zaman guncellenir                        |
| Controller'lar, Model'ler, Sayfalar, Route'lar      | Sadece kullanici degistirmemisse guncellenir |
| Kullanicinin kendi domain'leri                      | Asla dokunulmaz                              |
| Paketten gelen yeni dosyalar                        | Otomatik olarak eklenir                      |

## Paket Bilesenlerini Kullanma

### Vue Bilesenleri (yayinlamadan)

Bilesenler paketten otomatik olarak cozumlenir. Vue dosyalarinizda kullanin:

```vue
<template>
    <SkForm :config="formConfig" />
    <SkDatatable :config="tableConfig" />
    <SkTabs :config="tabConfig" />
</template>
```

### Ceviriler

```php
// Paket ad alanindan
__('starter-kit::admin.menu.dashboard')
__('starter-kit::message.created')
```

### Temel Siniflar

```php
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;
use Lvntr\StarterKit\Enums\PermissionEnum;
use Lvntr\StarterKit\Traits\HasActivityLogging;
```

## Lisans

[PolyForm Noncommercial 1.0.0](./LICENSE)
