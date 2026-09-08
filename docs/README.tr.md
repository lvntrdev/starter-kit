# Lvntr Starter Kit — Döküman Dizini

Tüm dökümanlar bu `docs/` klasöründe yer alır. Her konunun İngilizce ve Türkçe (`*.tr.md`) sürümü vardır.

## Başlangıç

| Döküman | Özet |
|---|---|
| [welcome.tr.md](./welcome.tr.md) | Kit nedir, ilk kurulumda neler sunar |
| [install.tr.md](./install.tr.md) | Temiz bir Laravel uygulamasından adım adım kurulum |
| [update.tr.md](./update.tr.md) | Mevcut bir projeye stub güncellemelerini güvenle çekme |
| [UPGRADE.tr.md](./UPGRADE.tr.md) | Kit sürümleri arasında geçiş yaparken kırıcı değişiklik notları |
| [project-info.tr.md](./project-info.tr.md) | Proje düzeyinde meta veriler ve uyumluluk matrisi |

## Backend / DDD

| Döküman | Özet |
|---|---|
| [ddd.tr.md](./ddd.tr.md) | Domain-first yapı: Action, DTO, Query, Event, Listener |
| [api.tr.md](./api.tr.md) | `/api/v1` altında versiyonlanmış JSON API; `ApiResponse` / `ApiException` zarfı |
| [api-clients.tr.md](./api-clients.tr.md) | Passport OAuth2 client ve Personal Access Token yönetimi için admin arayüzü |
| [api-routes.tr.md](./api-routes.tr.md) | API Routes admin modülü — uygulamanın route yüzeyini incele |
| [api-ai-metadata.tr.md](./api-ai-metadata.tr.md) | AI odaklı API metadata attribute'ları (`AiHint`, `AiPitfall`, `AiChangelog`, `AiExample`, `AiTool`, `ApiFeature`) ve `llms.txt` / MCP dışa aktarım yüzeyi |
| [module-routes.tr.md](./module-routes.tr.md) | Modül route kaydı — vendor route gruplarının consumer uygulamaya nasıl bağlandığı |
| [definitions.tr.md](./definitions.tr.md) | DB tabanlı enum arama sistemi (`DefinitionService`, `definition()` helper) |
| [wayfinder.tr.md](./wayfinder.tr.md) | Laravel Wayfinder ile üretilen tip güvenli TypeScript route fonksiyonları |

## Frontend

| Döküman | Özet |
|---|---|
| [formbuilder.tr.md](./formbuilder.tr.md) | `SkForm` + `FB` fluent builder: alan tipleri, düzen, doğrulama bağlantısı |
| [datatable.tr.md](./datatable.tr.md) | `SkDatatable` + `DB` builder: sütunlar, filtreler, bulk action, sayfalama |
| [tabs.tr.md](./tabs.tr.md) | Çok bölümlü ekranlar için `SkTabs` + `TB` builder |
| [composables.tr.md](./composables.tr.md) | Kit composable'ları: `useApi`, `useDialog`, `useCan`, `useDefinition` ve diğerleri |
| [ui-components.tr.md](./ui-components.tr.md) | UI yardımcıları: severity sistemleri (Button, Message, Toast, Tag), modal, picker |
| [admin-components.tr.md](./admin-components.tr.md) | `resources/js/pages/Admin/*` ekranları için sayfa kuralları |
| [theme.tr.md](./theme.tr.md) | Tema sistemi: `main` / `aura` anlık geçiş, dark mode, accent renk |
| [translatable-fields.tr.md](./translatable-fields.tr.md) | FormBuilder üzerinden JSON olarak saklanan çok dilli metin alanları |

## Özellikler

| Döküman | Özet |
|---|---|
| [file-manager.tr.md](./file-manager.tr.md) | Yeniden kullanılabilir dosya yöneticisi arayüzü — klasör ağaçları, context'ler, imzalı paylaşım linkleri |
| [files.tr.md](./files.tr.md) | Sistem geneli dosyalar için dosya yöneticisini bağlayan Global Files admin modülü |
| [auth.tr.md](./auth.tr.md) | Fortify web kimlik doğrulama + Passport OAuth2; 2FA, e-posta doğrulama, Turnstile |
| [roles-permissions.tr.md](./roles-permissions.tr.md) | `config/permission-resources.php` ile yönetilen Spatie yetki katmanı |
| [settings.tr.md](./settings.tr.md) | Ayarlar modülü: Genel, Kimlik Doğrulama, Mail, Depolama, Dosya Yöneticisi, Görünüm |
| [i18n.tr.md](./i18n.tr.md) | Locale ve çeviriler — `lang/` yapısı, `laravel-vue-i18n`, locale cookie |
| [activity-logs.tr.md](./activity-logs.tr.md) | Admin işlemleri ve model değişikliklerini inceleyen denetim log modülü |
| [logs.tr.md](./logs.tr.md) | `storage/logs/` içindeki dosyaları okuma, arama ve silme için log görüntüleyici |

## Operasyon

| Döküman | Özet |
|---|---|
| [artisan-commands.tr.md](./artisan-commands.tr.md) | Tam komut referansı: `sk:install`, `sk:update`, `sk:eject`, `make:sk-domain` ve diğerleri |
| [claude-skills.tr.md](./claude-skills.tr.md) | AI asistanlara kit kurallarını öğreten üç Claude Code skill |
| [project-documentation.tr.md](./project-documentation.tr.md) | Kurulum sonrasında kit mimarisinin üst düzey haritası |

---

> English version: [README.md](./README.md)
