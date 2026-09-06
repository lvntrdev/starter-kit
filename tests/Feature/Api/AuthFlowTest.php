<?php

/*
|--------------------------------------------------------------------------
| api/v1 auth flow — the shipped route file, driven end to end
|--------------------------------------------------------------------------
|
| Until this file existed the package suite had NO test that actually issued a
| request to `POST /api/v1/auth/login`. Every neighbouring test exercises one
| piece in isolation: TwoFactorChallengeConsumptionTest calls the 2FA action
| directly, OAuthCredentialRevocationTest calls LogoutUserAction directly,
| AuthSettingsTest asserts on the FortifyServiceProvider *source*. The one
| thing none of them covers is the composition — route file + middleware stack
| + FormRequest + controller + action + response envelope — which is exactly
| where MRG-05 lived: the API login endpoint had a per-IP throttle and no
| per-account throttle at all, so one account could be sprayed from many IPs.
|
| WHAT IS REAL HERE
|
|   - the shipped route file itself is `require`d (stubs/routes/api/public-api.php),
|     mounted the way stubs/routes/api.php mounts it: prefix api/v1, name
|     api.v1., middleware throttle:api;
|   - the shipped FortifyServiceProvider::boot() is run, so the login pipeline
|     and web limiters are the ones consumers get, not a copy; the `api-login`
|     limiter under test comes from the package provider Testbench already
|     boots, which is exactly where consumers get it from too;
|   - the shipped ApiExceptionHandler is registered through the same
|     Illuminate\Foundation\Configuration\Exceptions object bootstrap/app.php
|     hands it, so error bodies are the real envelope;
|   - the shipped User model, LoginRequest, LoginUserAction, LogoutUserAction,
|     AuthController and UserResource all run unmodified.
|
| THE ONE SEAM, AND WHY
|
| Passport's service provider is not booted under Testbench (see
| ActiveStatusEnforcementTest's header) and personal-access-token minting needs
| an authorization server with signing keys. Two narrow substitutions replace
| that infrastructure and nothing else:
|
|   1. PersonalAccessTokenFactory is swapped for one that writes a real
|      `oauth_access_tokens` row (plus its bound refresh token, exactly as the
|      password grant does) and returns the row id as the bearer string. Note
|      that HasApiTokens::createToken() — including getProviderName(), which
|      reads auth.guards/auth.providers — still runs for real above it.
|   2. The `passport` guard driver is registered as the same shape Passport
|      uses: a stateless RequestGuard that resolves the bearer token from
|      `oauth_access_tokens`, honours `revoked`, and calls withAccessToken() so
|      that $user->token() carries the row id the way the real guard does.
|
| The consequence is that a token issued by a real login is the same token
| logout later revokes — the flow is continuous, only the JWT machinery is not.
|
| NOT COVERED: POST /api/v1/auth/two-factor-challenge. Reaching it requires a
| login that returns a `requires_two_factor` challenge, and redeeming that
| challenge goes through Fortify's TwoFactorAuthenticationProvider. The
| redemption contract is already pinned, in far more depth than an HTTP test
| could, by tests/Feature/Settings/TwoFactorChallengeConsumptionTest.php; the
| ISSUING half is covered below (login returns a challenge and no token).
|
| Every password/secret-shaped literal in this file is synthetic.
|
*/

use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Actions\LogoutUserAction;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Laravel\Passport\AccessToken;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\PersonalAccessTokenResult;
use Lvntr\StarterKit\Exceptions\ApiExceptionHandler;
use Lvntr\StarterKit\Http\Middleware\ValidateTurnstile;
use Spatie\Permission\PermissionRegistrar;

$stubs = dirname(__DIR__, 3).'/stubs';

foreach ([
    User::class => '/app/Models/User.php',
    LoginUserAction::class => '/app/Domain/Auth/Actions/LoginUserAction.php',
    LogoutUserAction::class => '/app/Domain/Auth/Actions/LogoutUserAction.php',
    'App\Domain\Auth\DTOs\LoginDTO' => '/app/Domain/Auth/DTOs/LoginDTO.php',
    'App\Http\Controllers\Controller' => '/app/Http/Controllers/Controller.php',
    'App\Http\Requests\Api\Auth\LoginRequest' => '/app/Http/Requests/Api/Auth/LoginRequest.php',
    'App\Http\Resources\Admin\User\UserResource' => '/app/Http/Resources/Admin/User/UserResource.php',
    'App\Http\Controllers\Api\Auth\AuthController' => '/app/Http/Controllers/Api/Auth/AuthController.php',
    FortifyServiceProvider::class => '/app/Providers/FortifyServiceProvider.php',
] as $class => $path) {
    if (! class_exists($class)) {
        require_once $stubs.$path;
    }
}

/** Synthetic credential used by every fixture below. */
const API_AUTH_PASSWORD = 'Synthetic-Password-1!';

/**
 * The shipped consumer User model on a table this file owns.
 *
 * DatabaseTestCase's `users` shim is integer-keyed and has no `status`; the
 * shipped model is UUID-keyed and the status gate under test reads that column,
 * so the flow has to run against a table shaped like the published migration.
 */
class ApiAuthUser extends User
{
    protected $table = 'api_auth_users';
}

/**
 * Stands in for Passport's authorization server: writes the access/refresh
 * token PAIR the password grant would write and hands back the row id as the
 * bearer string. See the file header for why this substitution exists.
 */
class FakePersonalAccessTokenFactory extends PersonalAccessTokenFactory
{
    public function __construct() {}

    public function make(string|int $userId, string $name, array $scopes, string $provider): PersonalAccessTokenResult
    {
        $tokenId = Str::random(80);

        DB::table('oauth_access_tokens')->insert([
            'id' => $tokenId,
            'user_id' => $userId,
            'client_id' => API_AUTH_CLIENT_ID,
            'name' => $name,
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        DB::table('oauth_refresh_tokens')->insert([
            'id' => Str::random(80),
            'access_token_id' => $tokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(14),
        ]);

        return new PersonalAccessTokenResult([
            'access_token_id' => $tokenId,
            'access_token' => $tokenId,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ]);
    }
}

const API_AUTH_CLIENT_ID = '9d0f3333-4444-4555-8666-777788889999';

function apiAuthUser(array $attributes = []): ApiAuthUser
{
    return ApiAuthUser::create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-api@example.test',
        'password' => API_AUTH_PASSWORD,
        'status' => 'active',
    ], $attributes));
}

/**
 * POST the login endpoint from a named source address.
 *
 * The IP is the axis MRG-05 turned on: an attacker who owns a botnet moves it
 * freely, so a limiter that only counts per IP never fires for the account
 * being attacked.
 */
function apiLogin(string $email, string $password, string $ip = '203.0.113.10'): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function apiAuthAccessRevoked(string $tokenId): bool
{
    return (bool) DB::table('oauth_access_tokens')->where('id', $tokenId)->value('revoked');
}

function apiAuthRefreshRevoked(string $tokenId): bool
{
    return (bool) DB::table('oauth_refresh_tokens')->where('access_token_id', $tokenId)->value('revoked');
}

beforeEach(function (): void {
    config(['activitylog.enabled' => false]);
    config(['permission' => require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/config/permission.php']);
    app()->forgetInstance(PermissionRegistrar::class);

    // The rate-limit cases are only deterministic on a per-process store, and
    // the flush is what keeps one case's failed attempts out of the next one's
    // buckets (a fresh Testbench app per test is not a fresh file/redis store).
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    config(['services.turnstile.enabled' => false]);

    // ── Schema ──────────────────────────────────────────────────────────────
    Schema::create('api_auth_users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->timestamp('password_changed_at')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->rememberToken();
        $table->string('status')->nullable()->default('active');
        $table->string('timezone', 64)->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    foreach ([
        'roles' => function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        },
        'permissions' => function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        },
    ] as $name => $definition) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    Schema::create('model_has_roles', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->uuid('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->uuid('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('oauth_access_tokens', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->uuid('user_id')->nullable()->index();
        $table->uuid('client_id');
        $table->string('name')->nullable();
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->timestamps();
        $table->dateTime('expires_at')->nullable();
    });

    Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->char('access_token_id', 80)->index();
        $table->boolean('revoked');
        $table->dateTime('expires_at')->nullable();
    });

    // ── Auth wiring ─────────────────────────────────────────────────────────
    config(['auth.providers.users' => ['driver' => 'eloquent', 'model' => ApiAuthUser::class]]);
    config(['auth.guards.web' => ['driver' => 'session', 'provider' => 'users']]);
    // Declared with the `passport` driver because HasApiTokens::getProviderName()
    // resolves the provider by looking for exactly that driver — the real method
    // runs, so the real config shape has to be here.
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'users']]);

    Auth::extend('passport', fn ($app, $name, $config) => new RequestGuard(
        function (Request $request, UserProvider $provider): ?Authenticatable {
            $id = $request->bearerToken();

            if ($id === null) {
                return null;
            }

            $token = DB::table('oauth_access_tokens')
                ->where('id', $id)
                ->where('revoked', false)
                ->first();

            if ($token === null) {
                return null;
            }

            $user = $provider->retrieveById($token->user_id);

            // Passport's guard hands the row id over as the JWT `jti` claim;
            // RevokesOAuthCredentials reads it back off token(). Without this
            // the logout path would have nothing to revoke.
            return $user?->withAccessToken(new AccessToken(['oauth_access_token_id' => $id]));
        },
        request(),
        Auth::createUserProvider($config['provider']),
    ));

    app()->instance(PersonalAccessTokenFactory::class, new FakePersonalAccessTokenFactory);

    // ── Shipped provider + exception handler ────────────────────────────────
    // The login pipeline and web limiters come from this boot() call, not from
    // a copy living in this file. The api-login limiter under test is not here:
    // it is registered by the package provider Testbench boots, which is the
    // whole point — a published stub cannot be the only place it exists.
    (new FortifyServiceProvider(app()))->boot();

    ApiExceptionHandler::register(new Exceptions(app(ExceptionHandler::class)));

    // ── Routes: the shipped file, mounted the shipped way ───────────────────
    Route::aliasMiddleware('turnstile', ValidateTurnstile::class);

    Route::prefix('api/v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
        require dirname(__DIR__, 3).'/stubs/routes/api/public-api.php';

        Route::middleware('auth:api')->group(function (): void {
            require dirname(__DIR__, 3).'/stubs/routes/api/auth-route.php';
        });
    });

    Route::getRoutes()->refreshNameLookups();
});

// ──────────────────────────────────────────────────────────────────────────────
// 1. The happy path — envelope shape, not just a 200
// ──────────────────────────────────────────────────────────────────────────────

it('issues a token in the ApiResponse envelope for valid credentials', function (): void {
    $user = apiAuthUser();

    $response = apiLogin($user->email, API_AUTH_PASSWORD);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 200)
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonStructure(['success', 'status', 'message', 'data' => ['user', 'token']]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    // The hash must never ride along in the payload.
    expect($response->json('data.user'))->not->toHaveKey('password');

    // A real credential row was issued for this user, not just a string.
    expect(DB::table('oauth_access_tokens')->where('user_id', $user->id)->count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. Refusals — the contract as the controller actually writes it
// ──────────────────────────────────────────────────────────────────────────────

it('refuses a wrong password with 401 and no token', function (): void {
    $user = apiAuthUser();

    // 401, not 422: AuthController::login throws ApiException::unauthorized()
    // when the action returns null. 422 is reserved for the FormRequest.
    $response = apiLogin($user->email, 'Wrong-Password-9!');

    $response->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('status', 401)
        ->assertJsonPath('message', 'Invalid email or password.')
        ->assertJsonPath('data', null);

    expect(DB::table('oauth_access_tokens')->count())->toBe(0);
});

it('answers the same 401 for an email that does not exist', function (): void {
    // Identical status AND message to the wrong-password case: a different
    // answer would turn the endpoint into an account-enumeration oracle.
    apiLogin('nobody-'.uniqid().'@example.test', API_AUTH_PASSWORD)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Invalid email or password.');
});

it('rejects a malformed payload with 422 before authentication runs', function (): void {
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects a non-string email without letting the rate limiter cast it', function (): void {
    // The `api-login` limiter runs BEFORE LoginRequest and reads the raw body.
    // Casting an array to string there raises "Array to string conversion",
    // which the handler turns into a 500 on a payload that must answer 422.
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->postJson('/api/v1/auth/login', ['email' => ['a@b.test'], 'password' => 'secret-password']);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['email']);
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. The status gate — credentials alone are not enough
// ──────────────────────────────────────────────────────────────────────────────

it('refuses a correct password for an account whose status is not active', function (): void {
    $user = apiAuthUser(['status' => 'inactive']);

    apiLogin($user->email, API_AUTH_PASSWORD)
        ->assertStatus(401)
        ->assertJsonPath('message', 'Invalid email or password.');

    // The gate has to run BEFORE the token is minted, not after.
    expect(DB::table('oauth_access_tokens')->count())->toBe(0);
});

it('refuses an account with an unrecognised status too', function (): void {
    // LoginUserAction gates on `status !== 'active'`, so anything the operator
    // has not explicitly activated is refused — the opposite posture to
    // EnsureUserIsActive's deny-list, and deliberately so at the credential door.
    $user = apiAuthUser(['status' => 'pending_review']);

    apiLogin($user->email, API_AUTH_PASSWORD)->assertStatus(401);

    expect(DB::table('oauth_access_tokens')->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. MRG-05 — the per-account limiter
// ──────────────────────────────────────────────────────────────────────────────

it('throttles failed logins for one email spread across many source IPs', function (): void {
    $user = apiAuthUser();

    // THE REGRESSION. Before the `api-login` limiter the route carried
    // `throttle:5,1`, which buckets per IP only — so these four requests landed
    // in four separate buckets and every one of them was answered. An attacker
    // with a handful of addresses had no per-account ceiling at all.
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $ip) {
        apiLogin($user->email, 'Wrong-Password-9!', $ip)->assertStatus(401);
    }

    apiLogin($user->email, 'Wrong-Password-9!', '198.51.100.4')
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('status', 429)
        ->assertHeader('Retry-After');
});

it('locks out the correct password once the per-email budget is spent', function (): void {
    $user = apiAuthUser();

    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $ip) {
        apiLogin($user->email, 'Wrong-Password-9!', $ip)->assertStatus(401);
    }

    // A throttle that let the right password through would be decoration: the
    // guess that finally lands is exactly the request that must not be served.
    apiLogin($user->email, API_AUTH_PASSWORD, '198.51.100.5')->assertStatus(429);

    expect(DB::table('oauth_access_tokens')->count())->toBe(0);
});

it('keeps one throttled account from locking out every other account', function (): void {
    $victim = apiAuthUser(['email' => 'victim@example.test']);
    $bystander = apiAuthUser(['email' => 'bystander@example.test']);

    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $ip) {
        apiLogin($victim->email, 'Wrong-Password-9!', $ip);
    }

    // Different email, different bucket. A limiter keyed on something coarser
    // (or an empty key) would turn one attacked account into a site-wide outage.
    apiLogin($bystander->email, API_AUTH_PASSWORD, '198.51.100.9')->assertOk();
});

it('still enforces the endpoint 5-per-minute-per-IP ceiling it always had', function (): void {
    // The per-email limit is the addition; the per-IP limit is the ceiling the
    // route carried as `throttle:5,1` before it. Spreading across five distinct
    // emails keeps every email bucket at one attempt, so only the IP axis can
    // be what stops the sixth request.
    foreach (range(1, 5) as $n) {
        apiLogin("nobody-{$n}@example.test", API_AUTH_PASSWORD, '198.51.100.77')
            ->assertStatus(401);
    }

    apiLogin('nobody-6@example.test', API_AUTH_PASSWORD, '198.51.100.77')
        ->assertStatus(429);
});

// ──────────────────────────────────────────────────────────────────────────────
// 5. Two-factor — the issuing half (redemption lives in
//    tests/Feature/Settings/TwoFactorChallengeConsumptionTest.php)
// ──────────────────────────────────────────────────────────────────────────────

it('returns a challenge instead of a token when the account has 2FA confirmed', function (): void {
    config(['fortify.features' => [Features::twoFactorAuthentication()]]);

    $user = apiAuthUser();
    $user->forceFill([
        'two_factor_secret' => 'synthetic-encrypted-placeholder',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = apiLogin($user->email, API_AUTH_PASSWORD);

    $response->assertOk()
        ->assertJsonPath('data.requires_two_factor', true)
        ->assertJsonPath('message', 'Two-factor authentication required.');

    // The absence of `token` is the whole gate — its presence would make the
    // second factor optional.
    expect($response->json('data'))->not->toHaveKey('token')
        ->and($response->json('data.challenge'))->toBeString()->not->toBeEmpty()
        ->and(DB::table('oauth_access_tokens')->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 6. Logout revokes the PAIR, over HTTP, on the token login just issued
// ──────────────────────────────────────────────────────────────────────────────

it('revokes the access token and its bound refresh token on logout', function (): void {
    $user = apiAuthUser();

    $token = apiLogin($user->email, API_AUTH_PASSWORD)->json('data.token');

    expect(apiAuthAccessRevoked($token))->toBeFalse()
        ->and(apiAuthRefreshRevoked($token))->toBeFalse();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logged out.');

    // The refresh token is the half that matters: it outlives the access token
    // and mints new ones, so revoking only the access token would leave the
    // caller holding a live credential after "log out".
    expect(apiAuthAccessRevoked($token))->toBeTrue()
        ->and(apiAuthRefreshRevoked($token))->toBeTrue();
});

it('stops authenticating with the token it just revoked', function (): void {
    $user = apiAuthUser();

    $token = apiLogin($user->email, API_AUTH_PASSWORD)->json('data.token');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('refuses logout without a bearer token', function (): void {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});
