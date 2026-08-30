# Tabs

Starter kit, çok bölümlü ekranları temiz tutmak için `SkTabs` ve fluent `TB` builder yapısını kullanır. Ayarlar, profil ve benzeri ekranlar zamanla çok parçalı hale gelir — tabs yapısı, sayfayı birçok farklı route'a bölmeden tek route içinde düzenli bir arayüz kurar.

## İmportlar

```ts
import { TB } from '@lvntr/components/TabBuilder/core';
import SkTabs from '@lvntr/components/TabBuilder/SkTabs.vue';
import type { TabIconColor, TabBadgeSeverity } from '@lvntr/components/TabBuilder/core';
import type { TabChangePayload, SkTabsExposed, TabPanelMode, TabHistoryMode, TabUrlMode } from '@lvntr/components/TabBuilder/core';
```

## Temel Örnek

```vue
<script setup lang="ts">
import { TB } from '@lvntr/components/TabBuilder/core';
import SkTabs from '@lvntr/components/TabBuilder/SkTabs.vue';

const tabConfig = TB.tabs()
    .queryParam('tab')
    .addTabs(
        TB.item().key('general').label('Genel').icon('pi pi-user'),
        TB.item().key('security').label('Güvenlik').icon('pi pi-shield'),
        TB.item().key('sessions').label('Oturumlar').icon('pi pi-desktop'),
    )
    .build();
</script>

<template>
    <SkTabs :config="tabConfig">
        <template #general>
            <p>Genel içerik</p>
        </template>

        <template #security>
            <p>Güvenlik içeriği</p>
        </template>

        <template #sessions>
            <p>Oturum içeriği</p>
        </template>
    </SkTabs>
</template>
```

## Tabs Builder API

- `layout('horizontal' | 'vertical')`
- `vertical()`
- `horizontal()`
- `queryParam(string)`
- `class(string)`
- `cardTitle(string)`
- `cardSubtitle(string)`
- `isCard(boolean)`
- `addTabs(...tabs)`
- `lazy(value = true)` — yalnızca aktif paneli mount eder (`panels: 'active'`); `lazy(false)` override'ı temizler
- `keepAlive(value = true)` — her paneli mount edip geçişler arasında canlı tutar (`panels: 'all'`); `keepAlive(false)` override'ı temizler
- `history('push' | 'replace')` — sekme geçişinde yazılan history girdisi; varsayılan `replace`
- `urlMode('server' | 'client')` — `server` bir Inertia visit'i üzerinden senkronize eder (varsayılan), `client` sunucuya istek atmadan URL'i günceller
- `syncUrl(boolean)` — aktif sekmeyi URL query string'inde yansıtır; varsayılan `true`

## Tab Item API

- `key(string)`
- `label(string)`
- `icon(string)`
- `description(string)` — label altında ikincil satır (yalnızca dikey düzen)
- `iconColor(color)` — renkli icon tile preset'i (yalnızca dikey düzen); varsayılan `slate`. Seçenekler: `blue`, `amber`, `emerald`, `purple`, `teal`, `red`, `rose`, `indigo`, `slate`, `pink`, `orange`, `cyan`, `green`, `yellow`
- `badge(value, severity?)` — sağ tarafta badge (metin veya sayı). Severity: `success` / `warn` / `info` / `danger` / `secondary` (varsayılan)
- `checked(value = true)` — sağ tarafta yeşil check işareti; `badge` üzerinde önceliklidir
- `permission(...permissions)` — kullanıcı verilen yetkilerden en az birine sahip değilse sekmeyi gizler (variadic; birden çok değerde OR — `canAny()` ile aynı mantık)
- `role(...roles)` — kullanıcı verilen rollerden en az birine sahip değilse sekmeyi gizler (variadic; birden çok değerde OR)
- `visible(boolean | () => boolean)`
- `disabled(boolean | () => boolean)`
- `isCard(boolean)`
- `cardTitle(string)`
- `cardSubtitle(string)`

```ts
TB.item().key('billing').label('Faturalama').permission('billing.view', 'billing.manage'),
TB.item().key('admin-tools').label('Yönetici Araçları').role('admin', 'superadmin'),
```

## Bileşen Prop'ları ve Event'leri

- `config: TabBuilderConfig` — build edilmiş config (zorunlu)
- `v-model` (`modelValue?: string`) — aktif sekme anahtarı için opsiyonel iki yönlü binding. URL modunda mount sırasında bir deep link (örn. `?tab=security`), farklı bir `modelValue`'nun önüne geçer; local modda (`.syncUrl(false)`) ise `modelValue` başlangıç seçimini besler. Her iki durumda da `modelValue` yazmak, bir tıklamanın kullandığı aynı setter'dan geçer.
- `@update:modelValue="(key: string) => …"` — çözümlenen aktif anahtar `modelValue`'dan farklı olduğunda, mount sonrası dahil her seferinde tetiklenir
- `@change="(payload: TabChangePayload) => …"` — mount **sonrasındaki** her sekme değişiminde tetiklenir (ilk mount bir değişiklik sayılmaz); payload `{ key, previousKey, tab }` şeklindedir — daha önce çözümlenebilen bir sekme yoksa `previousKey` `null` olur
- `#empty` slot'u — seçilebilir sekme kalmadığında, yani `.permission()`/`.role()`/`.visible()` yüzünden tüm sekmeler elenmişse ya da görünür sekmelerin hepsi `.disabled()` ise, sidebar veya tab şeridi olmadan tek başına render edilir
- expose edilen instance (`SkTabsExposed`, template ref üzerinden) — `{ activeTab: string; isActive: (key: string) => boolean }`

```vue
<script setup lang="ts">
import { ref } from 'vue';
import type { TabChangePayload } from '@lvntr/components/TabBuilder/core';

const activeTab = ref('general');

function onTabChange(payload: TabChangePayload) {
    console.log(payload.previousKey, '→', payload.key);
}
</script>

<template>
    <SkTabs :config="tabConfig" v-model="activeTab" @change="onTabChange">
        <!-- ... -->
    </SkTabs>
</template>
```

## Zengin Dikey Tab Görünümü

Dikey tab'lar daha zengin bir sidebar sunabilir — renkli icon tile, description satırı, trailing badge veya check işareti. Sidebar zaten her zaman bir kart içinde render edilir; `.isCard(true)` bunun yerine aktif sekmenin **içerik** panelinin kart mı yoksa şeffaf, kenara yaslı bir panel mi olacağını belirler — `SkTabs` içindeki `tabIsCard()` fonksiyonunun okuduğu aynı flag:

```vue
<script setup lang="ts">
const tabConfig = TB.tabs()
    .vertical()
    .isCard(true)
    .addTabs(
        TB.item()
            .key('general')
            .label('Genel')
            .description('Uygulama adı, dil ve logo')
            .icon('pi pi-cog')
            .iconColor('blue'),
        TB.item()
            .key('mail')
            .label('E-posta')
            .description('SMTP ve gönderici ayarları')
            .icon('pi pi-envelope')
            .iconColor('emerald')
            .badge(3, 'warn'),
        TB.item()
            .key('storage')
            .label('Depolama')
            .description('S3, Spaces ve yerel disk')
            .icon('pi pi-database')
            .iconColor('purple')
            .checked(),
    )
    .build();
</script>
```

`description`, `iconColor`, `badge` ve `checked` yatay düzende yok sayılır.

## Yararlı Özellikler

- dikey veya yatay düzen
- icon tile, description, badge ve check işareti ile zengin dikey sidebar
- varsayılan olarak query string senkronizasyonu, `.syncUrl(false)` ile tamamen local (URL'siz) state
- role ve permission bazlı görünürlük
- sekme bazlı disabled mantığı
- hem sekme hem de konteyner seviyesinde başlık ve alt başlıkla opsiyonel card sarmalayıcı
- host tarafında tepki vermek için opsiyonel `v-model` binding'i ve bir `change` event'i
- seçilebilir sekme kalmadığında (hepsi elenmiş ya da görünenlerin hepsi disabled) gösterilecek bir `empty` slot'u
- dikey düzende tam klavye/ARIA desteği

## Dahili Davranışlar

`SkTabs` şu özellikleri hazır getirir:

- varsayılan olarak query string senkronizasyonu; `.syncUrl(false)` aktif sekmeyi tamamen local tutar
- dikey sidebar modu
- dikey düzende `sidebar-header` ve `sidebar-footer` slot'ları
- sekme anahtarına göre slot tabanlı içerik
- **lifecycle**: varsayılanlar değişmedi — dikey düzen yalnızca aktif paneli mount eder ve geçişte unmount eder, yatay düzen tüm panelleri bir kez mount edip görünürlüğü toggler; bu yüzden sekme bazlı local state varsayılan olarak yalnızca yatay düzende geçişten sağ çıkar. `.lazy()` her iki düzeni de yalnızca-aktif mount moduna zorlar (yatay düzende bu, PrimeVue'nun kendi `lazy` modudur); `.keepAlive()` her iki düzeni de her paneli mount edip canlı tutmaya zorlar — unmount yerine gizler (dikey düzende sekme bazlı state'i geçişler arasında korumak için kullanışlıdır)
- **URL senkronizasyonu**: `?tab=` görünür ve enabled bir sekmeyi adlandırmalı, aksi halde ilk seçilebilir sekme kazanır; disabled bir sekme URL'den asla aktive edilemez; aktif sekmeyi tekrar seçmek no-op'tur; `#hash` geçişler arasında korunur. `.urlMode('server')` (varsayılan) sayfayı yeniden çözümleyen bir Inertia visit'i üzerinden senkronize eder; `.urlMode('client')` sunucuya istek atmadan URL'i günceller. `.history('replace')` (varsayılan) her geçişte mevcut history girdisini değiştirir, `.history('push')` her geçişe kendi girdisini verir. `.syncUrl(false)` URL senkronizasyonunu tamamen kaldırır — aktif sekme yalnızca component state'inde (ve `v-model`'de) yaşar
- **erişilebilirlik (dikey düzen)**: tab listesi `aria-orientation="vertical"` ile `role="tablist"`'tır, her sekme butonu `aria-selected`/`aria-controls`/`aria-disabled` ve roving `tabindex` (aktif sekmede `0`, diğerlerinde `-1`) ile `role="tab"`'tır; panel ise `role="tabpanel"` ile sarmalanır. Arrow Down/Up, enabled sekmeler arasında odağı taşır (uçlarda başa/sona sarar), Home/End ilk/son enabled sekmeye atlar — yalnızca odak, manuel aktivasyon — Enter/Space ise butonun native click'i üzerinden seçim yapar. Sekme ikonları her iki düzende de `aria-hidden`'dır (adı label taşır); `.checked()` bir sekme durumunu, gizlenmiş check ikonunun yanındaki görsel olarak gizli metinle (`sk-common.completed`) duyurur. Yatay düzen PrimeVue'nun kendi erişilebilirliğini korur
- **builder doğrulaması**: `TB.item()…build()` boş veya yalnızca boşluktan oluşan bir key'de hata fırlatır; `TB.tabs()…build()` hiç sekme eklenmemişse hata fırlatır, development build'lerinde yinelenen bir sekme key'inde de hata fırlatır (production'da aynı mesajı `console.error` ile basar, dedupe yapmadan); `TB.tabs().queryParam()` boş veya yalnızca boşluktan oluşan bir isimde development build'lerinde hata fırlatır (production'da `console.error` basar ve önceden ayarlı ismi korur); her `build()` çağrısı taze bir snapshot döndürür — böylece aynı builder üzerindeki sonraki `.addTabs()` çağrıları veya döndürülen config'in mutate edilmesi, zaten build edilmiş bir config'i asla etkilemez
- aynı sayfadaki birden fazla `SkTabs` örneği farklı `.queryParam()` değerlerine ihtiyaç duyar
- `.permission()`/`.role()` filtrelemesi yalnızca sunum amaçlıdır — asıl veriyi sunucu tarafında yetkilendirin, gizli sekmenin verisini sayfa prop'larına serileştirmeyin

Gerektiğinde parent bileşenler aktif sekmeye `defineExpose` üzerinden erişebilir.

## Sekmeler Dialog İçinde

Bir dialog route'lanabilir bir sayfa değildir, bu yüzden sekmelerini URL query string'ine senkronlamak host sayfanın kendi `?tab=` parametresiyle çakışabilir (ya da basitçe anlamsızdır). Bunun yerine `.syncUrl(false)` çağırıp aktif sekmeyi `v-model` ile yönetin:

```vue
<script setup lang="ts">
import { ref } from 'vue';

const activeTab = ref('general');
const tabConfig = TB.tabs().syncUrl(false).addTabs(/* … */).build();
</script>

<template>
    <AppDialog>
        <SkTabs :config="tabConfig" v-model="activeTab">
            <!-- ... -->
        </SkTabs>
    </AppDialog>
</template>
```

## En Uygun Kullanım

- ayarlar ekranları
- profil ekranları
- mantıksal bölümlere ayrılmış uzun create/edit görünümleri
