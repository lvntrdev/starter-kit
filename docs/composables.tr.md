# Composable'lar

Kit composable'ları artık pakete dahil edilmiştir ve varsayılan olarak doğrudan vendor kütüphanesinden çalışır — tüketici uygulamasına kopyalanmaları gerekmez. Uygulama genelindeki importlar daha önce olduğu gibi `@/composables/<name>` (veya yalnızca `@/composables` barrel'ı) üzerinden yapılır; Vite `customResolver` ve buna eşlik eden tsconfig path girişi bu yolları **önce local, sonra vendor** olarak çözer: tüketicinin `resources/js/composables/` dizininde bir dosya varsa o kullanılır, yoksa vendor kopyası otomatik devreye girer.

**`useAdminMenu`** ve **`usePageHeader`**, stub olarak gönderilmeye devam eden tek composable'lardır (`resources/js/composables/useAdminMenu.ts`, `resources/js/composables/usePageHeader.ts`). `useAdminMenu`, tüketicinin ürettiği `@/routes/*` dosyalarına bağımlıdır ve projeye özgü menü tanımını barındırır; bu nedenle düzenlenebilir olarak kalmalıdır. `usePageHeader`, `AdminLayout.vue`'nun (kendisi de bir stub) sağladığı ve `UserForm.vue` gibi form sayfalarının tükettiği page-header context'ini tanımlar; bu nedenle layout ile birlikte düzenlenebilir bir stub olarak gönderilir. `@/composables/index.ts` barrel'ı da stub olarak kalmaya devam eder.

### Composer üzerinden composable güncellemeleri

Kit composable'ları pakette yer aldığından, `composer update lvntr/laravel-starter-kit` çalıştırıldığında otomatik olarak güncellenir. Elle dosya kopyalamaya gerek yoktur.

### Özelleştirmek için composable yayımlama

Bir composable'ı düzenlemek için önce tüketici uygulamasına yayımlayın:

```bash
php artisan sk:publish --tag=composables
```

Bu komut, vendor'daki güncel sürümleri `resources/js/composables/` dizinine kopyalar. Local kopya oluştuğunda local-first resolver onu otomatik olarak seçer — alias değişikliği veya build config düzenlemesi gerekmez.

### Mevcut kurulumlar için geçiş notu

Bu değişiklikten önce oluşturulan projelerde tüm composable'lar `resources/js/composables/` altında zaten mevcuttur. Local-first resolver bu local kopyaları kullanmaya devam eder; **hiçbir şey bozulmaz**. Ancak bu projelerde `composer update` ile gelen upstream düzeltmeleri otomatik olarak alınmaz. Vendor tarafından yönetilen güncellemelere geçmek için özelleştirmediğiniz composable dosyalarını `resources/js/composables/` dizininden silin — `useAdminMenu.ts`, `index.ts` ve kasıtlı olarak düzenlediğiniz dosyaları koruyun. Kit, local dosyaları hiçbir zaman otomatik silmez.

## Sık Kullanılan Composable'lar

- API response zarfını kullanan JSON istekleri için `useApi`
- Inertia shared props içinden role ve permission kontrolü için `useCan`
- cache'li definition yükleme için `useDefinition`
- dialog durumu ve uzaktan veri yükleme akışları için `useDialog`
- FileManager ve file-upload alanlarından tam ekran görsel önizleme için `useImageLightbox`
- onay işlemleri için `useConfirm`
- flash mesaj yönetimi için `useFlash`
- dark mode kalıcılığı için `useDarkMode`
- aktif runtime temasını (`main`/`aura`) uygulamak için `useTheme`
- Inertia yüklenme durumu için `usePageLoading`
- tablo veya widget yenilemek için `useRefreshBus`
- responsive sidebar durumu için `useSidebar`
- URL ile senkron sekme durumu için `useUrlTab`
- admin navigasyonu üretmek için `useAdminMenu` ve `useMenuBuilder`
- `AdminLayout` ile form sayfaları arasında paylaşılan geri-butonlu page-header context'i için `usePageHeader`

## Temel İstek ve Dialog Yardımcıları

### useApi()

Projenin `to_api()` / `ApiResponse` JSON yapısına göre hazırlanmış küçük bir `fetch()` sarmalayıcısıdır.

- `Accept: application/json` ve `X-Requested-With: XMLHttpRequest` header'larını ekler
- Uygunsa `X-XSRF-TOKEN` header'ını ekler
- `data` payload'ını doğrudan çözer
- Başarısız cevaplarda `ApiError` fırlatır
- `toast: false` verilmezse PrimeVue toast hata mesajı gösterebilir

```ts
const api = useApi();

const user = await api.get<User>('/api/v1/users/1');
await api.post('/api/v1/users', { name: 'John Doe' });
await api.put('/api/v1/users/1', { name: 'Jane Doe' });
await api.patch('/api/v1/users/1', { status: 'active' });
await api.delete('/api/v1/users/1');
```

### useConfirm()

PrimeVue `ConfirmationService` üzerine kurulmuş iki yardımcı döndürür:

- `confirmDelete(onAccept, message?, icon?)`
- `confirmAction({ message, onAccept, header?, icon?, acceptLabel?, rejectLabel?, acceptClass? })`

```ts
const { confirmDelete, confirmAction } = useConfirm();

confirmDelete(() => {
    console.log('Silme onaylandı');
});

confirmAction({
    message: 'Bu kaydı şimdi yayınlamak istiyor musun?',
    acceptLabel: 'Yayınla',
    onAccept: () => console.log('Onaylandı'),
});
```

### useDialog()

`@lvntr/components/ui/AppDialog.vue` ile birlikte çalışan global dialog yöneticisidir.

- `open(component, props?, header?, options?)`
- `openAsync(component, url, header?, options?, baseProps?)`
- `close()`
- `setLoading(val)`

Options içinde `refreshKey` verilirse `onSuccess` ve `onCancel` callback'leri otomatik eklenir.

### useImageLightbox()

`AdminLayout.vue` içindeki global `ImageLightbox` overlay'i üzerinden çalışan, ortak tam ekran görsel önizleme state'idir.

- `open(url, name?)`
- `close()`
- `state.visible`, `state.url`, `state.name`

Resimler için bunu kullanın. Resim olmayan dosyalarda `FilePreviewModal` ile `useDialog()` akışı kullanılmaya devam eder.

## Yetki ve Gezinme Yardımcıları

### useCan()

Inertia shared props içindeki permission ve role verilerini okur.

- `can(permission)`
- `canAny(permissions)`
- `hasRole(role)`

### useAdminMenu()

Projeye özel admin sidebar menü öğelerini tanımlar ve görünürlük ile aktiflik davranışını `useMenuBuilder()` composable'ına devreder.

### useMenuBuilder()

Sidebar benzeri gezinme yapıları için ortak menü yardımcısıdır.

- Üst seviye ve alt menü öğelerini permission ve role'a göre filtreler
- Filtreleme sonrası boşta kalan section başlıklarını kaldırır
- Düz URL'lerde ve query parametreli URL'lerde aktif linki doğru belirler
- Çocuk öğelerden biri aktifse parent group'u açık tutar

```ts
const allItems: MenuItem[] = [{ title: 'sk-menu.dashboard', href: '/dashboard' }];

return useMenuBuilder(allItems);
```

### useUrlTab()

Sekme seçimini `?tab=security` gibi bir query string anahtarı ile senkron tutar. `tabs` argümanı düz bir dizi (bir `reactive` dizi dahil — değişiklikler canlı izlenir, böylece mount sonrası değişen bir sekme listesi, örn. permission'a göre filtrelenen, senkron kalır), bir `ref` veya bir getter (`MaybeRefOrGetter<TabDefinition[]>`) kabul eder; her erişimde `toValue()` ile okunur. Aktif sekmeyi zaten seçili olduğu değere ayarlamak no-op'tur (navigasyon tetiklenmez); listenin ilk öğesine ayarlamak query parametresini yazmak yerine kaldırır; mevcut URL'deki `#hash` geçiş boyunca korunur. Opsiyonel üçüncü bir argüman olan `{ history: 'push' | 'replace' }`, geçişte yazılan history girdisini kontrol eder — `'replace'` (varsayılan) mevcut girdinin üzerine yazar, `'push'` her geçişe kendi girdisini verir. `SkTabs` artık doğrudan bu composable'ın üzerine kurulu değildir — kendi internal, işlevsel olarak eşdeğer bir aktif-sekme state'ine sahiptir; böylece davranışı bir uygulamanın yayınladığı `useUrlTab` kopyasına bağlı kalmaz.

### useRefreshBus()

Özellikle DataTable gibi bileşenler için kullanılan basit bir global yenileme bus'ıdır. Kayıtlı callback'ler bileşen unmount olduğunda otomatik temizlenir.

- `on(key, callback)` — refresh callback'i kaydet
- `refresh(...keys)` — bir veya birden fazla refresh anahtarını tetikle
- `refreshAll()` — tüm kayıtlı callback'leri tetikle

```ts
const bus = useRefreshBus();

bus.on('users-table', () => fetchData());
bus.refresh('users-table');
```

## UI State Yardımcıları

### useSidebar()

Admin sidebar için masaüstü daraltma ve mobil açık/kapalı durumlarını yönetir.

### useDarkMode()

Dark mode tercihini local storage'da saklar ve `<html>` üzerinde `.dark` sınıfını değiştirir.

### useTheme()

Inertia shared props'taki admin geneli `appearance.theme` değerine göre, `data-sk-theme` attribute'u üzerinden aktif runtime temasını (`main`, `aura`) `<html>` öğesine uygular.

- `theme` — çözümlenmiş runtime tema adı (`appearance` prop'unun eksik olduğu partial reload sırasında `undefined`)
- `runtimeThemes` — anında geçiş yapılabilen temaların kümesi; sunucu `runtime_themes` göndermezse `['main', 'aura']` varsayılanına döner
- `applyTheme(value)` — `<html>` üzerinde `data-sk-theme` attribute'unu ayarlar veya kaldırır

### usePageLoading()

`inertia:start` ve `inertia:finish` tarayıcı event'leri ile sayfa geçiş durumunu izler.

### useFlash()

Inertia shared props içindeki flash verisini reactive olarak sunar.

Bu projede flash mesajlar composable içinde değil, `AdminLayout.vue` içinde toast olarak gösterilir.

### usePageHeader()

`AdminLayout.vue`'nun sağladığı ve geri-butonlu form sayfalarının (örn. `UserForm.vue`, `RoleForm.vue`) başlık/alt başlığı ayrı bir page-header yerine ilk kartın içinde göstermek için okuduğu page-header injection context'ini sunar. `useAdminMenu` ile birlikte düzenlenebilir bir stub olarak gönderilir — yukarıdaki nota bakın.

- `active` — yalnızca Aura teması, geri butonu ve sayfanın `header-in-card` opt-in'i aynı anda sağlandığında `true`; aksi halde inject edilen varsayılan pasiftir
- `title`, `subtitle`, `goBack()` — opt-in yapılmış bir form sayfasının ilk kartı tarafından tüketilir

## Definition Yardımcıları

### useDefinition()

Definition kayıtlarını giriş gerektiren `/definitions` endpoint'inden yükler ve ortak bir reactive cache içinde tutar.

- `load(keys)` — yalnızca istenen key'leri yükler; ortak cache sayesinde tekrar istek atmaz
- `loadAll()` — mevcut tüm definition key'lerini yükler
- `list(key, filter?)` — ham definition öğelerini döner, isteğe bağlı filtreyle
- `options(key, filter?)` — select'ler için `{ label, value }` formatında öğeler döner
- `find(key, value)` — değere göre tek bir öğe bulur
- `clearCache()` — reactive cache'i sıfırlar
- `loaded` — herhangi bir yükleme tamamlandığında `true` olan reactive boolean

Bu projede tipik key'ler `userStatus`, `gender`, `identityType` ve `yesNo` değerleridir.

```ts
const { load, options, find } = useDefinition();

await load(['userStatus', 'gender']);

const statusOptions = options('userStatus');
const activeStatus = find('userStatus', 'active');
```

`list()` ve `options()`, definition listesinin alt kümesini almak istediğinde `only` veya `except` dizileri içeren isteğe bağlı bir `filter` nesnesi alır:

```ts
// Yalnızca active ve pending durumlarını göster
const filteredOptions = options('userStatus', { only: ['active', 'pending'] });

// Belirli bir durumu dışla
const filteredOptions = options('userStatus', { except: ['archived'] });
```

## DataTable Seçimi

### useDatatableSelection()

`SkDatatable` için satır seçimi ve toplu işlem (bulk action) gönderimini yönetir. Consumer sayfalar bunu doğrudan import eder — herhangi bir index ekranına onay kutusu ve toplu işlem eklemek için önerilen yol budur.

**Dışa aktarılan tipler:**

```ts
type BulkSelectionMode = 'page' | 'all';

interface BulkActionPayload {
    action: string;
    ids: (string | number)[];
    select_all_filtered: boolean;
    filter_snapshot: Record<string, unknown>;
    [key: string]: unknown;
}

interface BulkActionResult {
    processed: number;
    skipped: number;
    failed: Array<{ id: number | string; reason: string }>;
    message: string;
}
```

**İmza:**

```ts
const selection = useDatatableSelection({
    bulkUrl: string;        // Bulk endpoint'in mutlak URL'si (Wayfinder kullanın: users.bulk.url())
    idKey?: string;         // Satır ID property'si — varsayılan: 'id'
    onSuccess?: () => void; // Başarılı bulk işlem sonrası çağrılır (tabloyu yenilemek için)
});
```

**Döndürdükleri:**

| Özellik / Metod | Tip | Açıklama |
|---|---|---|
| `selectedIds` | `Ref<Set<string\|number>>` | Seçili satır ID'lerinin kümesi |
| `selectionMode` | `Ref<BulkSelectionMode>` | `'page'` (geçerli sayfa) veya `'all'` (çapraz sayfa filtrelenmiş) |
| `submitting` | `Ref<boolean>` | Bulk istek devam ederken true |
| `selectedCount` | `ComputedRef<number>` | Seçili ID sayısı |
| `hasSelection` | `ComputedRef<boolean>` | En az bir satır seçiliyse true |
| `isAllFilteredMode` | `ComputedRef<boolean>` | Çapraz sayfa seçimi aktifse true |
| `toggleRow(row)` | function | Bir satırı seç veya seçimi kaldır |
| `isRowSelected(row)` | function | Satır seçiliyse true döner |
| `togglePageSelection(rows, selected)` | function | Geçerli sayfadaki tüm satırları seç veya kaldır |
| `isPageFullySelected(rows)` | function | Sayfadaki tüm satırlar seçiliyse true |
| `isPagePartiallySelected(rows)` | function | Sayfadaki bazı (ama hepsi değil) satırlar seçiliyse true |
| `selectAllFiltered()` | function | Çapraz sayfa modunu etkinleştir — backend filtreye göre yeniden hesaplar |
| `clearSelection()` | function | Tüm seçimleri temizle ve page moduna geri dön |
| `executeBulkAction(action, filterSnapshot?, overrideUrl?)` | function | Bulk payload'ı Inertia router ile gönder |

**Kullanım:**

```ts
import { useDatatableSelection } from '@/composables/useDatatableSelection';
import { useRefreshBus } from '@/composables/useRefreshBus';
import users from '@/routes/users';

const bus = useRefreshBus();

const selection = useDatatableSelection({
    bulkUrl: users.bulk.url(),
    idKey: 'id',
    onSuccess: () => bus.refresh('users-table'),
});

// SkDatatable'a bağlayın:
// <SkDatatable :selection="selection" ...>

// Aktif filtrelerle bulk delete tetikleyin:
selection.executeBulkAction('delete', activeFilterSnapshot.value);
```

`selection` nesnesini `<SkDatatable :selection="selection">` ile bağlayın — bu onay kutusu sütununu oluşturur. Toplu işlem butonları `#bulk-actions` slot'una eklenir; satır seçiliyken `SkDatatable`, viewport'un altında kayan koyu bir işlem çubuğu gösterir. Backend'deki `BulkAction` arayüzü dahil tam toplu işlem deseni için `docs/datatable.md` belgesine bakın.

## Dahili Composable'lar

Aşağıdaki composable'lar vendor UI tarafından kullanılır ve consumer sayfalardan doğrudan çağrılmaları beklenmez. Referans amacıyla listelenmiştir.

### useFileShare() — Dahili

FileManager medyası için imzalı paylaşım linkleri oluşturmak ve iptal etmek amacıyla vendor `Files` modülü (`ShareLinkModal`, `MyShareLinksDrawer`) tarafından kullanılır. Consumer sayfalar bunu doğrudan çağırmaz — paylaşım linki işlemleri dahili Files arayüzü üzerinden sunulur. Paylaşım linki API'si için `docs/file-manager.md` belgesine bakın.

- `createShare(mediaId: number, ttlHours: number): Promise<ShareLinkResult | null>` — imzalı paylaşım linki oluşturur (TTL: 1–720 saat); `{ url, expires_at, token_hash }` veya hata durumunda `null` döner
- `revokeShare(mediaId: number, token: string): Promise<boolean>` — token hash ile mevcut bir linki iptal eder

### useAccentColor() — Dahili

Kullanıcı başına accent renk tercihini ve sidebar yüzeyini yönetmek için `AdminLayout` ve Appearance sekmesi tarafından kullanılır. Consumer'lar accent rengiyle header popover üzerinden etkileşime girer; bu composable'ı doğrudan çağırmaz. Accent renk sistemi için `docs/theme.md` belgesine bakın.

### useAppearanceDefaults() — Dahili

Her sayfa yüklenişinde Inertia shared props'tan global görünüm varsayılanlarını (accent renk, dark mode, sidebar stili, logo ve favicon URL'leri) okur. `useAccentColor`, `useDarkMode` ve layout'lar tarafından, kullanıcıya özgü herhangi bir override uygulanmadan önce başlangıç değerini belirlemek için kullanılır.

### getXsrfToken() — Dahili

`useCsrf.ts` dosyasından dışa aktarılır. Laravel `XSRF-TOKEN` cookie'sini okumak için tek doğruluk kaynağıdır — `useApi()`, FileManager upload XHR'ı ve zengin metin editörünün görsel yükleme akışı tutarlı cookie parsing için bunun üzerinden geçer. Cookie yoksa (SSR veya henüz set edilmemişse) `''` döner.

- `getXsrfToken(): string`

### withBasePath() — Dahili

`useBasePath.ts` dosyasından dışa aktarılır. Kendi URL'sini oluşturan ham `fetch`/`XMLHttpRequest` çağrıları için uygulamanın deploy alt-path'ini eklemek amacıyla vendor UI (zengin metin `EditorInput`, FileManager) tarafından kullanılır; Inertia navigasyonu base'i zaten otomatik olarak hesaba katar.

- `withBasePath(path: string): string`

## Öneri

Bir arayüz davranışı birden fazla sayfada görünmeye başladığında, aynı kodu tekrar etmek yerine bunu bir composable içine taşıyın.
