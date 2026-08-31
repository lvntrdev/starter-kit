<?php

/*
|--------------------------------------------------------------------------
| EnsureUserIsActive — mid-session access cut, fail-open contract
|--------------------------------------------------------------------------
|
| src/Http/Middleware/EnsureUserIsActive.php güvenlik-kritik davranışını uçtan
| uca doğrular. Bu, kit'in request pipeline'ındaki TEK kasıtlı sıkılaştırma:
| aktif oturumu olan bir kullanıcı pasifleştirildiğinde bir sonraki istekte
| kesilir. Karşılığında toplu kilitlenme riski taşır, bu yüzden testlerin
| ağırlığı FAIL-OPEN tarafındadır.
|
|   1. Deny matrisi — YALNIZ operatörün listelediği status keser. Bilinmeyen
|      string, null, status kolonu olmayan model, bool: HEPSİ geçer.
|   2. Kill switch — enforce_active_status=false ve boş deny-list.
|   3. Bayat published config — `security` bloğu bu sürümden ESKİ olan bir
|      consumer'da (shallow merge nedeniyle yeni anahtarlar görünmez) sabitler
|      config literal'lerini birebir üretmeli.
|   4. Guard çözümü — auth.guards'ta TANIMSIZ guard sessizce atlanır, throw
|      etmez; listelenmeyen guard hiç sorgulanmaz.
|   5. Sonlandırma şekli — JSON: 403 ApiResponse zarfı. Web: logout + session
|      invalidate + login'e redirect. Login route'u yoksa 403 (redirect döngüsü
|      YOK).
|   6. Wiring — `sk.active` alias'ı + web/api grubuna savunmacı append
|      (olmayan grubu YARATMAZ, mevcut kaydı ÇİFTLEMEZ).
|
| Testler DB'ye dokunmaz: kullanıcı actingAs ile doğrudan guard'a set edilir.
|
*/

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Lvntr\StarterKit\Http\Middleware\EnsureUserIsActive;
use Lvntr\StarterKit\StarterKitServiceProvider;

// ──────────────────────────────────────────────────────────────────────────────
// Test kullanıcıları — DB'ye yazılmaz, forceFill + exists ile "yüklenmiş" kabul
// edilir. actingAs() guard'a doğrudan set ettiği için sorgu çalışmaz.
// ──────────────────────────────────────────────────────────────────────────────

class ActiveStatusUser extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// `status` kolonu OLMAYAN consumer modeli: kilitlenmemeli.
class StatuslessUser extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// Eloquent OLMAYAN Authenticatable (token/LDAP tarzı consumer implementasyonu).
class PlainAuthenticatableUser implements Authenticatable
{
    public function __construct(public mixed $status = null) {}

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return 1;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value) {}

    public function getRememberTokenName()
    {
        return null;
    }
}

enum ActiveStatusEnum: string
{
    case Inactive = 'inactive';
    case Active = 'active';
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Status kolonu YÜKLENMİŞ bir kullanıcı üretir (null da geçerli bir değerdir).
 *
 * `remember_token` bilerek dolduruluyor: StarterKitServiceProvider::configureModels()
 * production dışında Model::shouldBeStrict() açar ve SessionGuard::logout()
 * getRememberToken() okur — gerçek bir users satırında o kolon vardır, burada
 * yoksa test gerçeğe uymayan bir MissingAttributeException üretir.
 */
function activeStatusUser(mixed $status): ActiveStatusUser
{
    $user = new ActiveStatusUser;
    $user->forceFill([
        'id' => 1,
        'email' => 'u@x.test',
        'status' => $status,
        'remember_token' => null,
    ]);
    $user->exists = true;

    return $user;
}

/** Status kolonu HİÇ olmayan kullanıcı. */
function statuslessUser(): StatuslessUser
{
    $user = new StatuslessUser;
    $user->forceFill(['id' => 1, 'email' => 'u@x.test', 'remember_token' => null]);
    $user->exists = true;

    return $user;
}

/**
 * Korunan rotalar: biri session'lı (web akışı), biri session'sız
 * (middleware'in session başlamadan çalıştığı hal — throw etmemeli).
 *
 * refreshNameLookups(): gerçek bir uygulamada RouteServiceProvider bunu
 * booted() üzerinde çağırır; boot SONRASI tanımlanan test rotalarında ad
 * tablosu bayat kalır ve route('login') çözülemez.
 */
function defineActiveStatusRoutes(): void
{
    Route::get('/sk-login', fn () => 'login page')->name('login');

    Route::middleware([StartSession::class, EnsureUserIsActive::class])
        ->get('/sk-guarded', fn () => 'ok');

    Route::middleware([EnsureUserIsActive::class])
        ->get('/sk-sessionless', fn () => 'ok');

    Route::getRoutes()->refreshNameLookups();
}

beforeEach(function (): void {
    config(['session.driver' => 'array']);
    defineActiveStatusRoutes();
});

// ──────────────────────────────────────────────────────────────────────────────
// 1. Deny matrisi — yalnız listelenen status keser
// ──────────────────────────────────────────────────────────────────────────────

it('lets a guest through untouched', function (): void {
    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('terminates a session whose status is on the deny-list', function (string $status): void {
    $this->actingAs(activeStatusUser($status), 'web');

    $this->get('/sk-guarded')->assertRedirect(route('login'));
})->with(['inactive', 'banned']);

it('matches the deny-list case-insensitively and trimmed', function (string $status): void {
    $this->actingAs(activeStatusUser($status), 'web');

    $this->get('/sk-guarded')->assertRedirect(route('login'));
})->with(['Inactive', '  INACTIVE  ', 'Banned']);

it('resolves a backed enum status through the cast boundary', function (): void {
    $this->actingAs(activeStatusUser(ActiveStatusEnum::Inactive), 'web');

    $this->get('/sk-guarded')->assertRedirect(route('login'));
});

it('lets an active account through', function (): void {
    $this->actingAs(activeStatusUser('active'), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

// Bu, planın "10 canlı projede toplu kilitlenme YOK" garantisinin taşıyıcısı:
// listelenmemiş HER değer geçer.
it('lets an unknown status through — fail-open is load-bearing', function (mixed $status): void {
    $this->actingAs(activeStatusUser($status), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
})->with([
    'unknown string' => 'pending_review',
    'null' => null,
    'empty string' => '',
    'integer zero' => 0,
    'integer one' => 1,
    'bool false' => false,
    'bool true' => true,
    'active enum' => ActiveStatusEnum::Active,
]);

it('lets a user model without a status attribute through', function (): void {
    $this->actingAs(statuslessUser(), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('still reads status from a non-Eloquent Authenticatable', function (): void {
    $this->actingAs(new PlainAuthenticatableUser('inactive'), 'web');

    $this->get('/sk-guarded')->assertRedirect(route('login'));
});

it('lets a non-Eloquent Authenticatable without a status property through', function (): void {
    $this->actingAs(new PlainAuthenticatableUser, 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. Kill switch ve deny-list konfigürasyonu
// ──────────────────────────────────────────────────────────────────────────────

it('passes everything through when the kill switch is off', function (): void {
    config(['starter-kit.security.enforce_active_status' => false]);
    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('treats an empty deny-list as a deliberate opt-out, not as "use the default"', function (): void {
    config(['starter-kit.security.active_status_denied' => []]);
    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('honours a custom deny-list verbatim', function (): void {
    config(['starter-kit.security.active_status_denied' => ['suspended']]);

    $this->actingAs(activeStatusUser('suspended'), 'web');
    $this->get('/sk-guarded')->assertRedirect(route('login'));

    $this->actingAs(activeStatusUser('inactive'), 'web');
    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. Bayat published config — mergeConfigFrom SIĞ birleştirir
// ──────────────────────────────────────────────────────────────────────────────

it('behaves identically when the published security block predates these keys', function (): void {
    // Bu sürümden ESKİ published config: `security` bloğu YALNIZ
    // csp_extra_origins taşır ve vendor kopyasını bütünüyle gizler.
    config(['starter-kit.security' => ['csp_extra_origins' => []]]);

    $this->actingAs(activeStatusUser('inactive'), 'web');
    $this->get('/sk-guarded')->assertRedirect(route('login'));

    $this->actingAs(activeStatusUser('pending_review'), 'web');
    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('keeps the shipped config literals and the code-side constants in sync', function (): void {
    // Sabitler bayat-config popülasyonu için literal'leri BİREBİR üretmeli;
    // ikisi ayrışırsa iki popülasyon farklı davranır.
    $shipped = require dirname(__DIR__, 3).'/config/starter-kit.php';

    expect($shipped['security']['enforce_active_status'])->toBe(EnsureUserIsActive::ENFORCE_DEFAULT)
        ->and($shipped['security']['active_status_denied'])->toBe(EnsureUserIsActive::DENIED_DEFAULT)
        ->and($shipped['security']['active_status_guards'])->toBe(EnsureUserIsActive::GUARDS_DEFAULT);
});

it('defaults the deny-list to exactly the non-active values the kit ships', function (): void {
    // stubs/database/seeders/_02_DefinitionSeeder.php `userStatus` tanımı
    // active / inactive / banned üretir — BAŞKA bir değer üretmez. Kit'in hiç
    // yazmadığı bir status'u (ör. `suspended`) varsayılan olarak engellemek
    // güvenlik kazancı sağlamaz, yalnız kilitlenme yüzeyi ekler.
    $seeder = file_get_contents(dirname(__DIR__, 3).'/stubs/database/seeders/_02_DefinitionSeeder.php');

    expect($seeder)->toContain("['active', 'Active'")
        ->and($seeder)->toContain("['inactive', 'Inactive'")
        ->and($seeder)->toContain("['banned', 'Banned'")
        ->and($seeder)->not->toContain("['suspended'")
        ->and(EnsureUserIsActive::DENIED_DEFAULT)->toBe(['inactive', 'banned']);

    // Kit'in kendi request kuralları da aynı üç değerle sınırlı.
    $rules = file_get_contents(dirname(__DIR__, 3).'/stubs/app/Http/Requests/Admin/User/UpdateUserRequest.php');

    expect($rules)->toContain("Rule::in(['active', 'inactive', 'banned'])");
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. Guard çözümü
// ──────────────────────────────────────────────────────────────────────────────

it('skips a configured guard that auth.guards does not declare', function (): void {
    config(['starter-kit.security.active_status_guards' => ['does-not-exist']]);
    $this->actingAs(activeStatusUser('inactive'), 'web');

    // Auth::guard('does-not-exist') fırlatırdı — sessizce atlanmalı.
    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('never consults a guard the operator left off the list', function (): void {
    config(['starter-kit.security.active_status_guards' => ['api']]);
    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->get('/sk-guarded')->assertOk()->assertSee('ok');
});

it('does not throw when it runs before the session is started', function (): void {
    $this->actingAs(activeStatusUser('pending_review'), 'web');

    $this->get('/sk-sessionless')->assertOk()->assertSee('ok');
});

// ──────────────────────────────────────────────────────────────────────────────
// 5. Sonlandırma şekli
// ──────────────────────────────────────────────────────────────────────────────

it('answers a JSON request with the documented 403 envelope', function (): void {
    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->getJson('/sk-guarded')
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'status' => 403,
            'message' => __('sk-auth.inactive'),
            'data' => null,
        ]);
});

it('logs the web session out and redirects to the login route', function (): void {
    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->get('/sk-guarded')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('sk-auth.inactive'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('reuses the same copy as the login-time block', function (): void {
    // FortifyServiceProvider stub'ı aynı anahtarı kullanır; kopya ayrışmamalı.
    $fortify = file_get_contents(dirname(__DIR__, 3).'/stubs/app/Providers/FortifyServiceProvider.php');

    expect($fortify)->toContain("__('".EnsureUserIsActive::MESSAGE_KEY."')")
        ->and(__(EnsureUserIsActive::MESSAGE_KEY))->not->toBe(EnsureUserIsActive::MESSAGE_KEY);
});

it('translates the copy in both shipped locales', function (string $locale): void {
    app()->setLocale($locale);

    expect(__(EnsureUserIsActive::MESSAGE_KEY))->not->toBe(EnsureUserIsActive::MESSAGE_KEY);
})->with(['en', 'tr']);

it('answers 403 instead of looping when no login route exists', function (): void {
    // Login route'u OLMAYAN app: redirect atmak sonsuz döngü olurdu.
    Route::setRoutes(new RouteCollection);
    Route::middleware([StartSession::class, EnsureUserIsActive::class])
        ->get('/sk-guarded', fn () => 'ok');

    $this->actingAs(activeStatusUser('inactive'), 'web');

    $this->get('/sk-guarded')->assertStatus(403);
});

// ──────────────────────────────────────────────────────────────────────────────
// 6. Wiring — alias + savunmacı grup append
// ──────────────────────────────────────────────────────────────────────────────

it('registers the sk.active alias', function (): void {
    expect(Route::getMiddleware())->toHaveKey('sk.active')
        ->and(Route::getMiddleware()['sk.active'])->toBe(EnsureUserIsActive::class);
});

/** Provider'ın gerçek append metodunu çağırır — kopya mantık test edilmez. */
function runActiveMiddlewareAttach(): void
{
    $provider = app()->getProvider(StarterKitServiceProvider::class);
    $method = new ReflectionMethod($provider, 'attachActiveUserMiddleware');
    $method->setAccessible(true);
    $method->invoke($provider, app('router'));
}

it('appends itself to the end of the web and api groups', function (): void {
    Route::middlewareGroup('web', [StartSession::class]);
    Route::middlewareGroup('api', ['throttle:api']);

    runActiveMiddlewareAttach();

    $groups = Route::getMiddlewareGroups();

    expect($groups['web'])->toBe([StartSession::class, EnsureUserIsActive::class])
        ->and($groups['api'])->toBe(['throttle:api', EnsureUserIsActive::class]);
});

it('never invents a middleware group the app does not define', function (): void {
    Route::middlewareGroup('web', [StartSession::class]);

    runActiveMiddlewareAttach();

    expect(Route::getMiddlewareGroups())->not->toHaveKey('api');
});

it('does not double-register when the consumer already wired it', function (mixed $entry): void {
    Route::middlewareGroup('web', [StartSession::class, $entry]);

    runActiveMiddlewareAttach();

    expect(Route::getMiddlewareGroups()['web'])->toBe([StartSession::class, $entry]);
})->with([
    'by class' => EnsureUserIsActive::class,
    'by alias' => 'sk.active',
]);
