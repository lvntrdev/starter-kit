# Datatable

Starter kit, tekrar kullanılabilir bir datatable yapısını iki parçalı sunar:

- frontend `SkDatatable` bileşeni
- backend `DatatableQueryBuilder`

## İmportlar

```ts
import { DB } from '@lvntr/components/DatatableBuilder/core';
import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';
```

## Frontend Builder

Tabloyu fluent `DB` API ile yapılandırın:

```vue
<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';
    import users from '@/routes/users';

    interface UserRow {
        id: string;
        full_name: string;
        email: string;
        role: string;
        status: string;
        created_at: string;
    }

    const tableConfig = DB.table<UserRow>()
        .route(users.dtApi.url())
        .addColumns(
            DB.column<UserRow>().label('sk-common.full_name').key('full_name'),
            DB.column<UserRow>().key('email'),
            DB.column<UserRow>().label('sk-common.role').key('role'),
            DB.column<UserRow>().key('status').tag('definition').tagKey('userStatus').tagOutlined(),
        )
        .addFilters(DB.filter().key('status').definitionOptions('userStatus'))
        .addActions(
            DB.action<UserRow>()
                .icon('pi pi-pencil')
                .severity('warn')
                .label('sk-button.edit')
                .handle((row) => console.log(row.id)),
        )
        .build();
</script>

<template>
    <SkDatatable :config="tableConfig" refresh-key="users-table" />
</template>
```

Temel yetenekler:

- sunucu taraflı sayfalama, arama, sıralama ve filtreleme
- inline veya panel filtreleri
- satır aksiyonları ve menü aksiyonları
- definition tabanlı tag kullanımı
- sticky kolon desteği

## Table Builder API

- `route(url)` — string, Wayfinder sonucu ya da `{ url }` döndüren callback kabul eder
- `sortable(enabled)`
- `pagination(enabled)`
- `searchable(enabled)`
- `isCard(enabled)`
- `cardTitle(title)`
- `cardSubtitle(subtitle)`
- `title(title)` — arama kutusunun solunda gösterilen toolbar başlığı
- `subtitle(subtitle)` — toolbar başlığının altındaki alt başlık
- `columnToggle(enabled)` — sütun görünürlük/sıralama menü butonunu aç/kapat (varsayılan: `true`)
- `perPage(count)`
- `idColumn(config | false)`
- `addColumns(...columns)`
- `addFilters(...filters)`
- `addActions(...actions)`
- `addMenuActions(...menuActions)`
- `menuButton(config)`
- `create(config)`

## Column Builder

- `key(string)`
- `label(string)`
- `sortable(boolean)`
- `render((row, escape) => string)`
- `tag('definition' | 'value', tagKey?)`
- `tagKey(key)`
- `tagLabels(map)` — value modunda label haritası (ham hücre değeri → görünen etiket)
- `tagSeverityKey(key)` — tag severity'sini taşıyan satır alanı (örn. backend'de seed edilen color kolonu); ikisi de eşleşirse `colors()` kazanır
- `colors(map)`
- `icons(map)`
- `tagIconPos('left' | 'right')`
- `tagSoft(enabled = true)`
- `tagRounded(enabled = true)`
- `tagOutlined(enabled = true)`
- `sticky()`
- `hidden()` — başlangıçta gizli; kullanıcı sütun menüsünden açabilir
- `visible(boolean)` — sütun menüsündeki başlangıç görünürlüğü (varsayılan: `true`)
- `locked(enabled = true)` — her zaman görünür, sütun menüsünden gizlenemez

Tag gösterimi artık definition tabanlıdır. `tag('definition')`, `userStatus` gibi bir definition key'i ile eşleşen kolonlarda kullanılır. `SkDatatable`, label, severity ve icon bilgisini definitions payload'undan çözer; istersen `colors({...})`, `icons({...})`, `tagSoft()`, `tagRounded()`, `tagOutlined()` ve `tagIconPos()` ile görünümü override edebilirsin.

```ts
DB.column<UserRow>()
    .key('status')
    .tag('definition', 'userStatus')
    .colors({
        active: 'emerald',
        inactive: 'rose',
    })
    .icons({
        active: 'pi pi-check-circle',
        inactive: 'pi pi-times-circle',
    })
    .tagIconPos('right')
    .tagOutlined()
    .tagRounded();
```

Definition olmayan değerler için (rol anahtarı gibi dinamik veriler) value modunu kullan: ham hücre değeri tag etiketi olur; istersen `tagLabels()` ile etikete, `colors()` ile renge map'lenir.

```ts
DB.column<UserRow>()
    .key('role')
    .tag('value')
    .tagLabels(Object.fromEntries(roleOptions.map((o) => [o.value, o.label])))
    .tagSeverityKey('role_color') // renk config/permission-resources.php → role_colors üzerinden seed edilir
    .tagSoft();
```

Notlar:

- `tagKey()`, `userStatus` gibi definition grup anahtarını belirtir
- `colors()` ve `icons()` mevcut satır değeri ile eşleşir
- override verilmezse `SkDatatable`, `useDefinition()` üzerinden gelen severity ve icon bilgisini kullanır
- value modunda definition lookup yapılmaz — etiket `tagLabels()`'tan gelir (yoksa ham değer), severity yalnızca `colors()` ile verilir

## Filter Builder

- `key(string)`
- `label(string)`
- `type('select' | 'select-button' | 'date' | 'daterange')`
- `options([...])`
- `definitionOptions(key)`
- `optionsUrl(url)`
- `placeholder(string)`
- `inline()`
- `placement('inline' | 'panel')`

`inline()` filtreler doğrudan toolbar'da görünür; `panel` filtreler (varsayılan) funnel butonunun arkasındaki popover'da yer alır. Funnel butonu ve popover **yalnızca en az bir `panel` filtre varsa** görünür — tüm filtreler `inline()` ise funnel/popover tamamen gizlenir ve inline filtreler popover içinde tekrarlanmaz.

Serbest metin arama, ayrı bir text filter type yerine tablo seviyesindeki `searchable(true)` arama kutusu ile yönetilir.

## Satır Aksiyonları

### Inline actions

Satırın içinde doğrudan görünen butonlar için `DB.action()` kullanılır.

- `icon`
- `severity`
- `size`
- `variant`
- `rounded`
- `raised`
- `text`
- `outlined`
- `label`
- `tooltip`
- `visible(fn)`
- `handle(fn)`

### Menu actions

Üç nokta menüsündeki aksiyonlar için `DB.menuAction()` kullanılır.

- `label`
- `icon`
- `separator`
- `visible(fn)`
- `handle(fn)`

## Toplu Aksiyonlar (Bulk Actions)

Toplu aksiyonlar, kullanıcının birden fazla satırı — sayfa değişse de — seçip tek bir backend işlemi çalıştırmasına olanak tanır. Seçim, belirli bir ID listesini ya da mevcut filtre durumuna uyan tüm satırları kapsayabilir.

### Frontend

`useDatatableSelection()` composable'ı ile bir seçim oluşturup `SkDatatable`'a `selection` prop'u ile verin — checkbox kolonu bununla render edilir. Toplu işlem butonlarını `#bulk-actions` slot'u ile sağlayın: satırlar seçiliyken `SkDatatable` ekranın alt-ortasına sabitlenmiş **yüzen koyu aksiyon barını** gösterir; seçim sayacı etiketi ve temizle (×) butonu bara gömülüdür, slot içeriği ikisinin arasında render edilir. Slot'a konan PrimeVue butonları koyu yüzeyde otomatik olarak ghost stile çevrilir (`variant="text"` kullanın; `severity="danger"` gül rengine döner).

```vue
<script setup lang="ts">
    const selection = useDatatableSelection({
        bulkUrl: users.bulk.url(),
        idKey: 'id',
        onSuccess: () => bus.refresh('users-table'),
    });
</script>

<template>
    <SkDatatable :config="tableConfig" :selection="selection" refresh-key="users-table">
        <template #bulk-actions>
            <Button
                :label="$t('sk-datatable.bulk_delete')"
                icon="pi pi-trash"
                size="small"
                severity="danger"
                variant="text"
                @click="confirmBulkDelete(totalFiltered)"
            />
        </template>
    </SkDatatable>
</template>
```

Eski desen — `#toolbar` slot'u içinde `.sk-dt-bulk-toolbar` bloğu render etmek — çalışmaya devam eder; yüzen bar yalnızca `#bulk-actions` slot'u verildiğinde görünür.

Bir aksiyon `executeBulkAction()` çağırdığında composable şu payload'u gönderir:

```json
{
    "action": "delete",
    "ids": ["uuid-1", "uuid-2"],
    "select_all_filtered": false,
    "filter_snapshot": {}
}
```

`select_all_filtered` `true` olduğunda `ids` boş gelir; `filter_snapshot` mevcut filtre durumunu taşır ve backend bu değerden filtrelenmiş kümeyi yeniden oluşturur.

Seçim, sayfa değişikliklerinde korunur. Backend yanıt verdikten sonra `onSuccess` ve `onError` Inertia router callback'leri tetiklenir.

### Request Doğrulama

`ids.*` alanı `string|min:1|max:64` kuralıyla doğrulanır. Bu kural; integer auto-increment anahtarları, UUID (36 karakter) ve ULID (26 karakter) formatlarını tipe özgü ayrı bir kural gerektirmeden karşılar. `ids` alanının kendisi yalnızca sayfa modunda zorunludur; `select_all_filtered` `true` olduğunda boş gelebilir ya da hiç gönderilmeyebilir, çünkü küme `filter_snapshot`'tan çözülür. Her iki modda da gönderilen id'ler 500 ile sınırlıdır.

### Backend

`BulkAction` interface'ini implemente edin:

```php
interface BulkAction
{
    public function handle(Collection $models, array $meta): BulkActionResult;
}
```

`BulkActionDispatcher`, `action` anahtarından doğru action sınıfını çözer; `ids` doluysa belirtilen model kümesini, `select_all_filtered` `true` ise tüm filtrelenmiş kümeyi aktarır.

### Select-all-filtered fail-closed sözleşmesi

Bir query sınıfı sayfalar-arası seçimi uyguladığında (ör. `UserBulkSelectionQuery`), datatable'ın kendi filtre koşullarını — gönderilen Users tablosu için bunlar `status`, `role`, `search`, `created_at_from`, `created_at_to` — `BulkFilterSnapshot::normalize()` üzerinden yeniden uygular. İstemcinin `filter_snapshot`'ında bulunan başka herhangi bir **aktif** `filter[...]` anahtarı sessizce düşürülmez; 422 (`sk-bulk.unknown_filters`) ile reddedilir, çünkü düşürmek kullanıcının gördüğü ve filtrelediği kümeden daha geniş bir küme çözer. Yalnızca `null` değer ya da boş dizi pasif sayılır — Spatie'nin `AllowedFilter`'ının kendisinin atladığı iki şekil. Boş ya da yalnızca boşluktan oluşan bir string aktif bir değerdir ve olduğu gibi (trim edilmeden) geçirilir; tablonun kendi koşuluyla uygulanır: `status` gibi bir exact filtre tablonun gösterdiği aynı boş kümeyi verir, `search` ve tarih sınırları ise tablonun yaptığı gibi onu yok sayar — böylece boş bir değer toplu kümeyi asla genişletemez; desteklenmeyen bir anahtardaki boş değer de diğer aktif değerler gibi reddedilir. Literal bir `true` / `false` string'i uygulanmadan önce boolean'a çevrilir — Spatie'nin `QueryBuilderRequest`'inin tablo için yaptığı aynı dönüşüm — böylece iki taraf da birebir aynı PHP değerini bağlar (örneğin tablonun `search` callback'i `true` alır ve "true" kelimesini değil `1`'i arar); kelime-arama koşulunun kendisi de tablonun `search` filtresi ile toplu sorguların ortak kullandığı `DatatableQueryBuilder::applySearchWords()`'tür. `ids` sayısal dönüşüm yapılmadan opak string olarak gönderilir ve doğrulanır (`string|min:1|max:64`), böylece UUID/ULID birincil anahtarlar değişmeden geçer.

`BulkActionResult` şu alanları taşır:

```php
new BulkActionResult(
    processed: 12,
    skipped: 1,
    failed: 0,
    message: '12 kullanıcı silindi.',
);
```

Controller, JSON response değil Inertia flash response döner:

```php
return back()->with('success', $result->message);
// veya
return back()->with('error', $result->message);
```

### Stub Örnekleri

**BulkDeleteUserAction** — aktif kullanıcının rank'ına eşit veya üstündeki kullanıcıları atlar:

```php
final class BulkDeleteUserAction implements BulkAction
{
    public function __construct(private readonly User $actor) {}

    public function handle(Collection $models, array $meta): BulkActionResult
    {
        $processed = 0;
        $skipped   = 0;

        foreach ($models as $user) {
            if ($user->rank >= $this->actor->rank) {
                $skipped++;
                continue;
            }
            $user->delete();
            $processed++;
        }

        return new BulkActionResult($processed, $skipped, 0);
    }
}
```

**BulkDeleteRoleAction** — sistem rollerini silme işlemine karşı korur:

```php
final class BulkDeleteRoleAction implements BulkAction
{
    public function handle(Collection $models, array $meta): BulkActionResult
    {
        $systemRoles = config('permission-resources.system_roles', []);
        $processed   = 0;
        $skipped     = 0;

        foreach ($models as $role) {
            if (in_array($role->name, $systemRoles, true)) {
                $skipped++;
                continue;
            }
            $role->delete();
            $processed++;
        }

        return new BulkActionResult($processed, $skipped, 0);
    }
}
```

## Özel Hücre Slot'ları

`SkDatatable`, kolon bazlı slot'ları `cell-{column.key}` isim kalıbı ile dışarı açar. Her slot şu verileri alır:

- `row`: satırın tüm objesi
- `value`: ilgili kolon anahtarı için çözülen değer

Slot içeriğinin dahili badge görünümü ile aynı olmasını istiyorsan PrimeVue'nun `<Tag>` bileşenini kullan (auto-import, ayrıca import gerekmez). `severity` hem 6 PrimeVue severity'sini hem de desteklenen SK palet adlarını (ör. `indigo`, `emerald`) kabul eder; soft/outlined `p-tag-soft` / `p-tag-outlined` sınıflarıyla opt-in'dir:

```vue
<template>
    <SkDatatable :config="tableConfig">
        <template #cell-status="{ row, value }">
            <Tag :value="String(value)" :severity="row.is_active ? 'success' : 'danger'" rounded class="p-tag-soft" />
        </template>
    </SkDatatable>
</template>
```

Eşleşen bir `cell-*` slot'u varsa o kolonun dahili görünümü, definition tag'leri dahil olmak üzere, bununla override edilir.

## Sütun Görünürlüğü ve Sıralama

Toolbar'da canlı `görünür/toplam` sayaçlı bir sütun menüsü butonu bulunur. Menüden kullanıcı:

- sütunları açıp kapatabilir — `locked()` sütunlar her zaman görünür kalır, işareti kaldırılamaz
- tutamaçtan sürükleyerek sütunları yeniden sıralayabilir — dahili ID ve seçim checkbox sütunları sabittir, yerinden oynamaz
- "Tümünü göster" ile her şeyi geri getirebilir

Sütun durumu (sıra + gizli küme) tablonun diğer durumuyla birlikte `sessionStorage` içinde kalıcıdır. Özelliğin tamamı `columnToggle(false)` ile kapatılır.

`SkDatatable` her veri çekişinde görünür sütun anahtarlarını `columns=key1,key2` query parametresiyle gönderir. Opt-in yapmayan backend'ler bunu yok sayar; `DatatableQueryBuilder::columns()` tanımlayan backend'ler payload'ı seçime göre şekillendirir (aşağıya bakın).

### Sunucu kaynaklı sütun listesi

Backend sütun listesini tanımladığında response `columns` meta dizisi taşır. `SkDatatable` bu listeyi key bazında lokal config'in üzerine merge eder: mevcut sütunları, sırayı, label'ları ve varsayılan görünürlüğü sunucu listesi belirler — frontend config'inde hiç olmayan (ör. başlangıçta gizli ekstra) sütunlar dahil — render katmanını (tag, custom render, sticky) ise lokal `DB.column()` config'i sağlamaya devam eder. Sunucu listesinde olmayan client-only sütunlar listenin ardından render edilmeye devam eder.

## Toolbar Slot'ları

- `#toolbar-start` — aksiyon grubunun içinde, **create butonunun solunda** render edilir (ör. bir Dışa Aktar butonu)
- `#toolbar` — create butonundan sonra render edilir (bulk-action toolbar'ı burayı kullanır)

```vue
<SkDatatable :config="tableConfig">
    <template #toolbar-start>
        <Button label="Dışa aktar" icon="pi pi-download" severity="secondary" outlined />
    </template>
</SkDatatable>
```

## Backend Builder

Controller içinde ya da özel query sınıflarında `DatatableQueryBuilder` kullanın:

```php
return DatatableQueryBuilder::for(User::query())
    ->searchable(['name', 'email'])
    ->sortable(['id', 'name', 'email', 'created_at'])
    ->filterable(['status'])
    ->columns([
        ['key' => 'name', 'locked' => true],
        'email',
        ['key' => 'created_at', 'visible' => false],
    ])
    ->alwaysInclude(['name'])
    ->defaultSort('-created_at')
    ->response();
```

### Sütun tanımı ve payload şekillendirme

`columns()` tablonun sunduğu sütun listesini tanımlar. Her giriş bir key string'i ya da opsiyonel `label`, `sortable`, `visible`, `locked` bayraklı bir dizidir. Liste `columns` meta'sı olarak döner — böylece frontend menüsü başlangıçta gizli sütunları da sunabilir — ve payload şekillendirmeyi açar: **fail-closed** — satırın tamamı yalnızca istekte `columns` parametresi hiç yoksa döner. Parametre mevcutsa her satır, `alwaysInclude()` anahtarlarına (varsayılan `['id']`) ve tanımlı bir sütun anahtarıyla gerçekten eşleşen istek anahtarlarına indirgenir; hiçbir anahtar eşleşmese bile satır yalnızca `alwaysInclude()` anahtarlarına indirgenir — tam satıra asla geri dönmez. Satır aksiyonlarının görünürlükten bağımsız ihtiyaç duyduğu alanlar (confirm diyaloğu için isim, URL'ler vb.) için `alwaysInclude()` kullanın. `role.name` gibi dot anahtarlarda üst seviye `role` segmenti korunur. Tanımlı sütun anahtarları frontend sütun anahtarlarıyla birebir eşleşmelidir, aksi halde ilgili hücreler boş render edilir.

### Arama semantiği

`searchable()` gelen `filter[search]` değerini boşluklara göre kelimelere
böler. Her kelime listelenen her kolona `LIKE '%kelime%'` ile eşlenir
(kolonlar arası OR) ve **tüm kelimelerin eşleşmesi gerekir** (kelimeler
arası AND). Yani `['name', 'email']` üzerinde `filter[search]=ali veli`
sorgusu; hem `ali` hem `veli`'nin name veya email alanlarından birinde
geçtiği satırları döner. Arama değerindeki `%` ve `_` karakterleri
escape'lenerek literal olarak aranır.

Çağıran tarafın `perPage()` kullanmadığı ve `?per_page=` parametresinin
bulunmadığı durumda varsayılan sayfa büyüklüğü
`config('starter-kit.datatable.default_per_page')` üzerinden okunur ve
tanımlı değilse `10`'a düşer.

`?per_page=` değerinin üst sınırı `config('starter-kit.datatable.max_per_page')`
(veya `STARTER_KIT_DATATABLE_MAX_PER_PAGE` env var'ı) ile belirlenir; bu
anahtar tanımlı değilse `100`'e düşer. Üst sınırın üstündeki istekler
sessizce bu tavana çekilir — meşru çağrıları kırmadan sunucuyu kazara
veya kötü niyetli büyük-payload taleplerinden korur.

## Önerilen Kullanım

Büyük modüllerde datatable mantığını `app/Domain/*/Queries/*DatatableQuery.php` altında tutup controller içine o query sınıfını enjekte edin.

## Beklenen Yanıt Yapısı

`SkDatatable` şu tipte bir payload bekler:

```json
{
    "data": [],
    "total": 0,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1,
    "from": null,
    "to": null
}
```

Backend `columns()` tanımladığında payload ek olarak bir `columns` dizisi taşır (`[{ "key": "email", "visible": false, ... }]`).

## Dahili Davranışlar

`SkDatatable` şunları hazır olarak getirir:

- server-side arama, sıralama, sayfalama ve filtreleme
- tag kolonları ve definition tabanlı filtreler için otomatik definition yükleme
- PrimeVue `<Tag>` üzerinden definition tabanlı label, severity ve icon gösterimi
- paylaşılabilir tablo URL'leri için query string senkronizasyonu
- sayfa yenilemelerinde `sessionStorage` kalıcılığı
- `refresh-key` ile opsiyonel refresh bus entegrasyonu
- dahili per-page kontrolleri
- görünürlük anahtarları ve sürükle-bırak sıralamalı sütun menüsü (ID/checkbox sütunları sabit)
- sütun bazlı veri çekme: görünür sütunlar `columns=` ile gönderilir, açıksa sunucu tarafında şekillendirilir
- `cell-{column.key}` slot'ları ile kolon bazlı özel render override'ı
- çekilen satırları dışarı veren `load` eventi

## İyi Kullanım Alanları

- admin kullanıcı listeleri
- rol listeleri
- işlem kayıtları
- filtre, aksiyon ve sunucu taraflı sayfalama gerektiren tüm kaynaklar
