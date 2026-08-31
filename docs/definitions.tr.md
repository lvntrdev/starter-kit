# Definitions

Definitions, form, filtre ve tag alanlarında kullanılan label/value çiftleri için ortak bir lookup sistemidir.

## Saklama ve Yönetim

Definitions kayıtları veritabanında tutulur. Admin arayüzünden CRUD işlemi yapılamaz; yönetim seeder ve migration aracılığıyla gerçekleştirilir.

- Migration: `database/migrations/2026_03_12_001950_create_definitions_table.php`
- Seeder: `database/seeders/_02_DefinitionSeeder.php`

## Veritabanı Kolonları

`definitions` tablosu şu kolonlara sahiptir:

| Kolon | Tür | Notlar |
|---|---|---|
| `key` | string | indeksli; ilişkili definition'ları gruplar |
| `value` | string | saklanan değer |
| `label` | string | okunabilir görüntü etiketi |
| `explanation` | text | nullable; ek açıklama |
| `severity` | string | nullable; örn. `info`, `warning`, `danger` |
| `icon` | string | nullable; ikon tanımlayıcısı |
| `is_active` | boolean | varsayılan `true` |
| `order` | integer | varsayılan `0`; sıralamayı belirler |
| `visibility` | boolean | varsayılan `true` |
| `lang` | string(35) | varsayılan `en`; i18n desteği sağlar |

`(key, value, lang)` üçlüsü üzerinde tekil kısıt uygulanır.

> **`lang` neden 255 değil 35 karakter?** Üç adet varsayılan 255 karakterlik `utf8mb4` kolon üzerindeki bileşik tekil indeks, InnoDB'nin 3072 baytlık anahtar sınırına karşı 3060 bayt ölçer — herhangi bir kolonda tek bir karakterlik pay kaldığı için tamamen kırılmaya bir adım uzaktadır. `2026_08_31_120000_narrow_definitions_unique_index_columns` migration'ı `lang` kolonunu 35'e daraltır; bu, kitin herhangi bir yerde kabul ettiği en geniş locale değeridir (`content_languages.code`) ve ~892 baytlık pay bırakır. `key` ve `value` yayınlanmış 255 genişliğini korur. Migration önce mevcut tüm satırları ölçer — soft-delete edilmiş olanlar dahil — ve **tek bir satır bile kırpılacaksa şemayı hiç değiştirmeden reddeder**. Her iki yön de tekil indeksin varlığını doğrulayarak biter (isim, tekillik ve tam olarak `{key, value, lang}` kolon kümesi); böylece indeksi kaymış veya eksik gelen bir tablo, garantisi olmadan "migrate edildi" diye kaydedilmek yerine gerçek indeksi yeniden kurar. Bkz. [UPGRADE.tr.md](UPGRADE.tr.md).

## Erişim Noktaları

- web service route: `/definitions`
- API route: `/api/v1/definitions`
- frontend composable: `useDefinition()`

## Frontend Faydaları

Definitions sayesinde:

- select seçenekleri kolay üretilir
- status tag'leri tutarlı render edilir
- aynı anlam farklı sayfa ve modüllerde ortak şekilde kullanılır

## Yaygın Metotlar

`useDefinition()` içinden:

- `load(keys)`
- `loadAll()`
- `list(key)`
- `options(key)`
- `find(key, value)`
- `clearCache()`

