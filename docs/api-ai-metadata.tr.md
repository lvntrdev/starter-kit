# API Uç Noktaları İçin AI Metadata

`lvntr/api-dock`, `App\Http\Controllers\Api\*` altındaki controller sınıf ve metotlarından altı PHP attribute'unu okuyup üretilen OpenAPI dokümanına vendor extension olarak ekler. Amaçları, bir uç noktayı parametre ve response şeklinin zaten söylediğinin ötesinde bir AI ajanına (ya da `/api-dock`'ta gezinen bir insana) kendini anlatır hale getirmek: bir alanın neden öyle davrandığı, nerede tuzak olduğu, çalıştırılabilir bir örnek, işlemin MCP tool olarak dışa açılıp açılmadığı ve davranış değişikliklerinin insan tarafından yazılmış geçmişi.

Hiçbiri zorunlu değil. Attribute eklenmemiş bir controller yalnızca Scramble ile de düzgün dokümante olur; attribute'ları yalnızca temel OpenAPI çıktısının söylemediği bir şey varsa ekleyin.

## Altı Attribute

Kaynak: `vendor/lvntr/api-dock/src/Attributes/`.

| Attribute | Hedef | Tekrarlanabilir | Constructor |
| --- | --- | --- | --- |
| `AiHint` | sınıf veya metot | hayır | `string $hint` |
| `AiPitfall` | sınıf veya metot | **evet** | `string $text, int $order = 0` |
| `AiChangelog` | sınıf veya metot | **evet** | `string $date, string $summary, bool $breaking = false` |
| `AiExample` | sınıf veya metot | **evet** | `string $name, array $request = [], array $response = []` |
| `AiTool` | sınıf veya metot | hayır | `bool $enabled = true, ?string $name = null, ?string $description = null` |
| `ApiFeature` | sınıf veya metot | hayır | `?string $auth = null, ?array $scopes = null, ?int $rateLimit = null, ?string $rateLimitPer = null, ?bool $deprecated = null, ?string $stability = null` |

`AiHint`, `AiTool` ve `ApiFeature` tekil değerlidir: aynı sınıf veya metoda ikincisini eklemek fazladan olanın okunmadan kalmasına yol açar (yalnızca ilk attribute örneği alınır). `AiPitfall`, `AiChangelog` ve `AiExample` ise `IS_REPEATABLE` olarak tanımlanmıştır ve üst üste eklenmek üzere tasarlanmıştır.

## Birleştirme Sırası

Her işlem için `AiMetadataOperationExtension` ve `FeatureOperationExtension`, **önce sınıf seviyesindeki attribute'ları, sonra metot seviyesindekileri** okur ve şu şekilde birleştirir:

- **`AiHint`**: metot seviyesinde varsa doğrudan o kazanır, yoksa sınıf seviyesindeki hint kullanılır. Birleştirme yoktur — dokümana yalnızca tek bir hint ulaşır.
- **`AiPitfall`**: sınıf ve metot pitfall'ları art arda eklenir, ardından `order` alanına göre kararlı (stable) biçimde sıralanır (eşitlik durumunda bildirim sırası korunur — sınıf metottan önce).
- **`AiChangelog`**: sınıf ve metot girdileri art arda eklenir, ardından **en yeni tarih önce** gelecek şekilde sıralanır; `date` alanı ayrıştırılamayan girdiler en sona düşer, eşitlikte bildirim sırası korunur.
- **`AiExample`**: önce sınıf örnekleri, sonra metot örnekleri, bu sırayla — sıralama veya tekilleştirme yapılmaz.
- **`AiTool`**: metot seviyesinde varsa doğrudan o kazanır, yoksa sınıf attribute'u kullanılır (`AiHint` ile aynı tekil-değer kuralı).
- **`ApiFeature`**: bu attribute tek bir örneği seçmek yerine alan alan birleşir. Sınıf seviyesindeki `ApiFeature`'ın `null` olmayan her alanı uygulanır, ardından metot seviyesindekinin `null` olmayan her alanı bunun üzerine uygulanır — yani metot attribute'u yalnızca kendi belirlediği alanları geçersiz kılar ve **`null` bir alan önceki değeri değiştirmeden bırakır**. `auth`, `rateLimit`/`rateLimitPer`, `deprecated` ve `stability` alanları, herhangi bir `ApiFeature` override'ı uygulanmadan önce ayrıca route middleware'inden (`auth`, `can`/`ability`/`permission`/`scope*`, `throttle`) ve `#[Deprecated]` attribute'undan da beslenir.

## Üretilen Vendor Extension'lar

Her attribute değeri, üretilen OpenAPI işleminde `x-` önekli bir anahtar altına yerleşir:

| Extension anahtarı | Kaynak attribute(lar) |
| --- | --- |
| `x-ai-hint` | `AiHint` |
| `x-ai-pitfalls` | `AiPitfall` (sıralı dizi) |
| `x-ai-examples` | `AiExample` (dizi) |
| `x-ai-tool` | `AiTool` (`{enabled, name, description}`) |
| `x-api-dock-changelog` | `AiChangelog` (sıralı dizi) |
| `x-api-dock-features` | `ApiFeature` + çıkarımlanan middleware (`{auth, scopes, rate_limit, deprecated, stability}`) |

## Uygulamalı Örnek

Kit'in kendi `App\Http\Controllers\Api\UserController` sınıfındaki (`stubs/app/Http/Controllers/Api/UserController.php`) `store()` metodunu, `to_api()` ile zaten `ApiResponse` zarfı üzerinden dönen haliyle, işaretlemek:

```php
use LvntR\ApiDock\Attributes\AiChangelog;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\ApiFeature;

#[AiHint('Yönetici tarafından yönetilen bir kullanıcı oluşturur; çağıranın zaten users.create yetkisi olmalı.')]
#[ApiFeature(scopes: ['users.create'])]
class UserController extends Controller
{
    #[AiPitfall('email, aktif VE soft-delete edilmiş kullanıcılar arasında benzersiz olmalı — yeniden davet, hata vermek yerine mevcut satırı yeniden kullanır.', order: 1)]
    #[AiPitfall('roles oluştururken opsiyoneldir; boş bırakılması kullanıcıyı varsayılan bir rolle değil, rolsüz bırakır.', order: 2)]
    #[AiExample(
        name: 'Destek personeli oluştur',
        request: ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'roles' => ['support']],
        response: ['success' => true, 'status' => 201, 'message' => 'User created successfully.'],
    )]
    #[AiChangelog(date: '2026-08-01', summary: 'roles opsiyonel hale geldi; önceden zorunluydu.', breaking: true)]
    public function store(StoreUserRequest $request, CreateUserAction $action): ApiResponse
    {
        // ...
    }
}
```

Sınıf seviyesindeki `AiHint` ve `ApiFeature`, bir metot bunları geçersiz kılmadığı sürece controller'daki tüm metotlara (`index`, `show`, `update`, `destroy` dahil) uygulanır; yukarıdaki pitfall'lar, örnek ve changelog ise yalnızca `store()`'a özeldir.

## Dışa Aktarım Yüzeyi

`php artisan api-dock:export`, aynı işaretlenmiş OpenAPI dokümanından üretilen AI odaklı çıktıları yazar:

- **`--llms`** → `llms.txt`, bir LLM bağlam penceresine yapıştırılmak veya `/llms.txt` altında servis edilmek üzere API'nin düz metin özeti.
- **`--mcp`** → `mcp-tools.json`, MCP (Model Context Protocol) tool tanımları. `config/api-dock.php` içindeki `api-dock.ai.mcp_opt_in` `true` yapıldığında yalnızca `AiTool` attribute'u taşıyan işlemler dışa aktarılır; varsayılan `false` iken her işlem uygundur.
- **`--openapi`** → extension'lar dahil, düz üretilmiş `openapi.json`.

Çıktılar `config('api-dock.ai.export_path')` konumuna yazılır (varsayılan `storage_path('api-dock')`).

Ayrıca `php artisan api-dock:sync`, OpenAPI dokümanını yeniden üretir, `config('api-dock.snapshot.path')` konumundaki saklanan snapshot ile karşılaştırır, `breaking` / `additive` / `cosmetic` değişiklikleri raporlar (makine-okur biçim için `api-dock:diff --json`) ve snapshot'ı günceller.

**`AiChangelog` ile snapshot diff'i bilinçli olarak birbirinden farklı iki şeydir.** `AiChangelog`, dokümantasyon panelini veya `llms.txt`'i okuyan bir insan entegratöre yönelik, elle yazılan bir attribute'tur — `summary`'de ne yazarsanız onu söyler, hatırladığınız her ne sıklıkta yazarsanız o sıklıkta. Snapshot diff'i ise iki OpenAPI dokümanının alan alan karşılaştırılmasından makine tarafından üretilir — kimse yazmasa da her şema kaymasını yakalar. Diff'i bir şeyin değiştiğini *tespit etmek* için, `AiChangelog`'u ise *neden* değiştiğini ve ne yapılması gerektiğini söylemek için kullanın. Hiçbiri diğerinin yerini tutmaz.

## Güvenlik

`/api-dock` panelinin içindeki try-it proxy'si **varsayılan olarak açıktır** (`config('api-dock.try_it.enabled')`), ancak `try_it.allowed_hosts` ve `try_it.self_hosts` her ikisi de **boş** gelir — boş oldukları sürece proxy yalnızca bu uygulamanın kendi host'una ulaşabilir, keyfi bir yabancı host'a değil (bir girdi eklemeden önce SSRF-yüzeyi gerekçesi için `stubs/config/api-dock.php` dosyasına bakın).

`/api-dock` yüzeyinin tamamı — panel, spec, try-it proxy'si ve kimlik-bilgisi-profili uç noktaları — `['web', 'auth', CheckApiDocsAccess::class]` (`config('api-dock.middleware')`) arkasındadır ve `Gate::allows('viewApiDocs')` sağlanmadıkça reddeder. Kit bu gate'i, varsayılan olarak `developer` rolüne verilen, seed edilmiş `api-docs.read` yetki yeteneğine bağlar (`config/permission-resources.php`) ve **kapalı** tarafta hata verir: seed edilmemiş bir yetenek, izin vermek yerine reddeder.
