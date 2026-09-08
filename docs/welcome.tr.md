# Lvntr Starter Kit'e Hoş Geldiniz

Lvntr Starter Kit, Laravel projelerine ilk günden kullanılabilir bir admin panel temeli eklemek için hazırlanmış modern bir başlangıç paketidir. Kimlik doğrulama, yetkilendirme, ayarlar, dosya yönetimi, API erişimi ve geliştirici araçları tek bir tutarlı yapı içinde gelir.

## Üretime Yakın Admin Temeli

Kullanıcı, rol, yetki, aktivite kaydı, ayarlar ve dashboard ekranları hazır gelir. Yeni projelerde tekrar tekrar kurulan admin ihtiyaçlarını azaltır ve ekibin iş mantığına daha hızlı odaklanmasını sağlar.

## Modern Laravel Stack

Paket; Laravel 13, PHP 8.4+, Inertia.js v3, Vue 3, PrimeVue 4 ve Tailwind CSS 4 üzerine kuruludur. Backend ve frontend tarafında güncel, genişletilebilir ve paketlenmiş bir mimari sunar.

## Güvenli Kimlik Doğrulama

Fortify tabanlı giriş, kayıt, şifre sıfırlama, e-posta doğrulama ve iki faktör doğrulama akışları desteklenir. Oturum yönetimi ve Cloudflare Turnstile entegrasyonu ile güvenlik katmanı güçlendirilir.

## Rol ve Yetki Yönetimi

Spatie Permission ile rol tabanlı erişim kontrolü sağlanır. Kaynak bazlı yetkiler sayesinde admin ekranları ve işlemler kullanıcının rolüne göre dinamik olarak yönetilebilir.

## Ayarlar Paneli

Genel, kimlik doğrulama, mail, depolama, dosya yöneticisi, API entegrasyonları, API istemcileri/token'ları ve System Health yüzeyleri tek panelden yönetilir. Uygulama davranışını kod değişikliği yapmadan kontrol etmek için merkezi bir alan sunar.

## Dosya Yönetimi

File Manager; medya yükleme, klasörleme, çöp kutusu, geri yükleme ve imzalı paylaşım linkleri gibi temel dosya operasyonlarını destekler. Context tabanlı yapı farklı modül ve sahiplik senaryolarına uyum sağlar.

## API ve Token Yönetimi

Laravel Passport ile OAuth2 API erişimi, API client yönetimi ve personal access token akışları desteklenir. Admin paneli üzerinden API istemcileri ve token'lar kontrol edilebilir. Uç noktalar ayrıca [AI odaklı metadata](./api-ai-metadata.tr.md) taşıyabilir — ipucu, tuzak, örnek ve changelog — bunlar `llms.txt` ve MCP tool tanımları olarak dışa aktarılır.

## Sistem Sağlığı

`sk:doctor` komutu ve admin sistem sağlığı ekranı; veritabanı, Redis, Passport anahtarları, storage linki, mail, queue ve build artifact'ları gibi kritik noktaların durumunu kontrol eder.

## DDD Odaklı Proje Yapısı

Action, DTO, Query, Event ve Listener katmanlarıyla iş mantığı daha okunabilir ve test edilebilir şekilde ayrılır. `make:sk-domain` komutu yeni domain'leri hızlı ve tutarlı biçimde oluşturur.

## Builder Bileşenleri

FormBuilder, DatatableBuilder ve TabBuilder API'ları tekrar kullanılabilir admin arayüzleri kurmayı kolaylaştırır. Translatable alanlar, toplu işlemler ve sayfa aşımı seçim gibi ihtiyaçlar hazır desteklenir.

## Aktivite Kayıtları ve İzlenebilirlik

Kullanıcı ve rol işlemleri gibi önemli domain olayları activity log'a işlenir. Trace ID ve API response yapıları, hata takibi ve operasyonel izlenebilirlik için ek bağlam sağlar.

## Güvenli Güncelleme Akışı

`sk:update` hash takibiyle stub güncellemelerini yönetir ve kullanıcı değişikliklerini korumaya odaklanır. `sk:publish` ile yalnızca ihtiyaç duyulan kaynaklar seçici olarak yayınlanabilir.

## Hazır Başlangıç, Esnek Genişleme

Starter kit, projeye hazır bir admin omurgası verirken çekirdek yapının genişletilmesine de alan bırakır. Ekipler aynı temel ekranları yeniden yazmak yerine kendi ürün özelliklerini daha hızlı inşa edebilir.
