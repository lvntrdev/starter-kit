# Lvntr Starter Kit

### Admin odaklı Laravel starter kit.

![Tests](https://img.shields.io/badge/tests-passing-22c55e?style=flat-square)
![License](https://img.shields.io/badge/license-PolyForm--Noncommercial%201.0.0-f59e0b?style=flat-square)
![Packagist Sürüm](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

> ## ⚠️ UYARI
>
> Bu depo aktif geliştirme aşamasındadır ve sık sık değişikliklere tabidir. Projenin stabilitesi henüz garanti altına alınmamıştır. Kullanmadan önce lütfen aşağıdaki noktaları göz önünde bulundurun:
>
> 1. **Kod Değişiklikleri:** Dizin yapısı veya çekirdek sınıflar, önceden haber verilmeksizin radikal değişikliklere uğrayabilir.
> 2. **Güncelleme Süreci:** Güncellemeler her zaman otomatik bir geçiş (migration) yolu sunmayabilir. Güncelleme komutlarını çalıştırmanın yanı sıra, `README` veya `CHANGELOG` dosyalarını kontrol ederek elle müdahale yapmanız gerekebilir.
> 3. **Risk:** Yapılan önemli değişiklikler, mevcut projenizde veri kaybına veya kırıcı (breaking) hatalara yol açabilir.

## Tanıtım

Lvntr Starter Kit; **Laravel 13**, **Inertia.js v3**, **Vue 3**, **PrimeVue 4** ve **Tailwind CSS 4** üzerine kurulmuş, tam donanımlı bir Laravel admin panel paketidir.

Resmi Laravel starter kit'leri yalnızca kimlik doğrulama iskeletiyle gelirken, bu paket daha ilk kurulumda production-ready bir admin paneli sunar: kullanıcılar, roller, yetkiler, aktivite kayıtları, ayarlar, dosya yöneticisi, 2FA ve genişletebileceğin DDD tarzı bir domain katmanı.

Her projede aynı admin ekranlarını sıfırdan yazmak istemeyip doğrudan iş mantığına odaklanmak isteyen ekipler için tasarlandı.

> **Web Sitesi & Dökümantasyon:** [starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)
> Kurulum rehberi, bileşen referansları, mimari notlar ve örnekler.

## Ekran Görüntüleri

![Koyu & Açık temalar](https://starter-kit.lvntr.dev/shots/dark-light.png)

![Giriş ekranı](https://starter-kit.lvntr.dev/shots/auth-login.png)

![Kullanıcı yönetimi](https://starter-kit.lvntr.dev/shots/admin-users.png)

![Roller ve yetkiler](https://starter-kit.lvntr.dev/shots/admin-permissions.png)

![Dosya yöneticisi](https://starter-kit.lvntr.dev/shots/admin-file-manager.png)

## İçinde Neler Var?

- **Kimlik Doğrulama**
    - Giriş / Kayıt / Şifre Sıfırlama
    - E-posta Doğrulama
    - İki Faktörlü Doğrulama (Fortify)
    - Laravel Passport ile OAuth2 API
- **Kullanıcı ve Erişim Yönetimi**
    - Avatar yükleme ve soft delete destekli kullanıcı CRUD
    - Roller ve dinamik kaynak bazlı yetkiler (Spatie)
    - Oturum yönetimi
- **Admin Modülleri**
    - Dashboard
    - Aktivite Kayıtları (gözatılabilir, filtrelenebilir)
    - Ayarlar paneli (Genel / Kimlik Doğrulama / Mail / Depolama / Dosya Yöneticisi)
    - Pluggable context'lere sahip Dosya Yöneticisi
    - API Route tarayıcısı
    - Definitions (form ve tablolarda kullanılan DB tabanlı enum'lar)
- **Geliştirici Araçları**
    - DDD tarzı domain katmanı (Action / DTO / Query / Event / Listener)
    - FormBuilder, DatatableBuilder, TabBuilder fluent API'ları
    - `make:sk-domain` ile domain iskeleti üretimi
    - `sk:update` ile güvenli güncelleme (hash tabanlı, kullanıcı değişikliklerini korur)
    - Açık & koyu tema

## Nasıl Kullanılır?

Temiz bir Laravel kurulumundan başla:

```bash
composer create-project laravel/laravel my-app
cd my-app
composer require lvntr/laravel-starter-kit:^13.0
php artisan sk:install
```

Hepsi bu kadar. Kurulum sihirbazı migration, seeder, Passport anahtarları, varsayılan admin kullanıcısı ve frontend build işlemlerini otomatik yapar.

Detaylı adım adım rehber: [starter-kit.lvntr.dev/docs/install](https://starter-kit.lvntr.dev/docs/install)

## Gereksinimler

- PHP 8.4+
- Laravel 13
- Node.js 18+
- MySQL veya MariaDB

## Dökümantasyon

Kurulum, güncelleme akışı, domain scaffolding, FormBuilder / DatatableBuilder / TabBuilder API'ları, composable'lar, dosya yöneticisi, roller ve yetkiler, OAuth2 API, aktivite kayıtları, ayarlar — her şey resmi sitede:

**[starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)**

## Lisans

[PolyForm Noncommercial 1.0.0](./LICENSE)
