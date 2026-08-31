<?php

/*
|--------------------------------------------------------------------------
| Active-status enforcement — end to end, both directions
|--------------------------------------------------------------------------
|
| tests/Feature/Auth/EnsureUserIsActiveTest.php pins the middleware in
| isolation (no database, user set straight onto the guard). THIS file pins the
| feature as an operator experiences it: a row in a users table, a session and
| an OAuth token, and the production write path — UpdateUserAction ->
| RevokeUserAccessAction — flipping the status.
|
| Both directions are load-bearing and both are asserted here:
|
|   CUT     an account deactivated mid-session is blocked on its next web
|           request (session invalidated), its live OAuth token answers with
|           the 403 envelope instead of 200, and the transition drops the
|           token, its refresh token, its authorization code and its device
|           code — exactly once.
|
|   PASS    the backward-compatibility population is untouched: status null,
|           an unrecognised status, a user model with no status attribute at
|           all, and an install that turned `enforce_active_status` off. None
|           of them is blocked and none of them loses a credential.
|
| ── SIMULATING "THE NEXT REQUEST" ───────────────────────────────────────────
|
| A production request is a fresh process, so the session guard re-reads the
| account from the database every time. Inside one test the AuthManager caches
| the resolved guard AND the user object it already loaded, so a status change
| written to the database would be invisible to a second $this->get(). Every
| test therefore calls enforcementNextRequest() (Auth::forgetGuards()) between
| requests — that, not a helper convenience, is what makes the second request
| read the account from the database the way production does.
|
| ── THE `api` GUARD ─────────────────────────────────────────────────────────
|
| Passport's own service provider is not booted under Testbench, so the
| `passport` driver that StarterKitServiceProvider::configurePassport()
| configures has no implementation here. The guard registered in beforeEach()
| is the same SHAPE Passport uses (a stateless RequestGuard) and resolves the
| user from an `oauth_access_tokens` row, honouring `revoked` — so a token this
| suite revokes really does stop authenticating.
|
*/

use App\Domain\Role\Queries\RoleSelectOptionsQuery;
use App\Models\User;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Domain\User\Actions\RevokeUserAccessAction;
use Lvntr\StarterKit\Domain\User\Actions\UpdateUserAction;
use Lvntr\StarterKit\Domain\User\DTOs\UserDTO;
use Lvntr\StarterKit\Http\Middleware\EnsureUserIsActive;
use Spatie\Permission\PermissionRegistrar;

if (! class_exists(User::class)) {
    require_once dirname(__DIR__, 3).'/stubs/app/Models/User.php';
}

// ──────────────────────────────────────────────────────────────────────────────
// Models
// ──────────────────────────────────────────────────────────────────────────────

/**
 * The shipped consumer User model, pointed at a table this file owns.
 *
 * DatabaseTestCase's `users` shim is deliberately minimal (integer key, no
 * `status`), and the shipped model is UUID-keyed, so the enforcement path has
 * to run against a table matching the migration the kit actually publishes.
 */
class EnforcementUser extends User
{
    protected $table = 'enforcement_users';
}

/**
 * A consumer user model with NO `status` column — the population that must
 * never be locked out of its own panel.
 */
class StatuslessEnforcementUser extends AuthUser
{
    protected $table = 'statusless_enforcement_users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Counts how often UpdateUserAction asks for a revocation, and how often that
 * ask actually schedules one. The production action still runs underneath, so
 * the database assertions in the same test are produced by shipped code.
 */
class CountingRevokeUserAccessAction extends RevokeUserAccessAction
{
    public int $calls = 0;

    public int $scheduled = 0;

    public function execute(Authenticatable $user, mixed $fromStatus, mixed $toStatus): bool
    {
        $this->calls++;

        $result = parent::execute($user, $fromStatus, $toStatus);

        $this->scheduled += $result ? 1 : 0;

        return $result;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Fixtures
// ──────────────────────────────────────────────────────────────────────────────

const ENFORCEMENT_CLIENT_ID = '9d0f1111-2222-4333-8444-555566667777';

function enforcementUser(array $attributes = []): EnforcementUser
{
    return EnforcementUser::create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'password' => 'Valid-Password-1!',
        'status' => 'active',
    ], $attributes));
}

/**
 * A full credential set for the account: access token + refresh token +
 * authorization code + device code. RevokeUserAccessAction claims to take all
 * four, and an unredeemed code is the one that could still MINT a new token
 * after the access tokens are gone.
 *
 * @return string the access token id
 */
function enforcementCredentials(EnforcementUser $user): string
{
    $tokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->getKey(),
        'client_id' => ENFORCEMENT_CLIENT_ID,
        'name' => 'test token',
        'scopes' => '[]',
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

    DB::table('oauth_auth_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => ENFORCEMENT_CLIENT_ID,
        'scopes' => '[]',
        'revoked' => false,
        'expires_at' => now()->addMinutes(10),
    ]);

    DB::table('oauth_device_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => ENFORCEMENT_CLIENT_ID,
        'user_code' => Str::upper(Str::random(8)),
        'scopes' => '[]',
        'revoked' => false,
        'user_approved_at' => now(),
        'last_polled_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);

    return $tokenId;
}

/** Whether every credential class of this account is still live. */
function enforcementCredentialsLive(EnforcementUser $user, string $tokenId): bool
{
    return DB::table('oauth_access_tokens')->where('id', $tokenId)->where('revoked', false)->exists()
        && DB::table('oauth_refresh_tokens')->where('access_token_id', $tokenId)->where('revoked', false)->exists()
        && DB::table('oauth_auth_codes')->where('user_id', $user->getKey())->where('revoked', false)->exists()
        && DB::table('oauth_device_codes')->where('user_id', $user->getKey())->where('revoked', false)->exists();
}

/** Whether every credential class of this account has been revoked. */
function enforcementCredentialsRevoked(EnforcementUser $user, string $tokenId): bool
{
    return ! DB::table('oauth_access_tokens')->where('id', $tokenId)->where('revoked', false)->exists()
        && ! DB::table('oauth_refresh_tokens')->where('access_token_id', $tokenId)->where('revoked', false)->exists()
        && ! DB::table('oauth_auth_codes')->where('user_id', $user->getKey())->where('revoked', false)->exists()
        && ! DB::table('oauth_device_codes')->where('user_id', $user->getKey())->where('revoked', false)->exists();
}

/** Put the account in the session the way a login does. */
function enforcementLogin(Authenticatable $user): void
{
    test()->withSession([Auth::guard('web')->getName() => $user->getAuthIdentifier()]);
}

/**
 * Simulate the process boundary between two requests: drop the cached guards
 * so the next request re-reads the account from the database.
 */
function enforcementNextRequest(): void
{
    Auth::forgetGuards();
}

/** The admin "save user" write path, with the fields the form posts. */
function enforcementUpdate(
    EnforcementUser $user,
    array $overrides = [],
    ?RevokeUserAccessAction $revoker = null,
): EnforcementUser {
    $action = $revoker === null ? new UpdateUserAction : new UpdateUserAction($revoker);

    return $action->execute($user, UserDTO::fromArray(array_merge([
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'status' => $user->status,
    ], $overrides)));
}

// ──────────────────────────────────────────────────────────────────────────────
// Environment
// ──────────────────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    config(['activitylog.enabled' => false]);
    config(['session.driver' => 'array']);
    config(['auth.providers.users.model' => EnforcementUser::class]);
    config(['permission' => require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/config/permission.php']);
    app()->forgetInstance(PermissionRegistrar::class);

    // Mirrors stubs/database/migrations/0001_01_01_000000_create_users_table.php,
    // except `status` is nullable here: the shipped column is NOT NULL, but a
    // consumer whose column allows null is precisely the fail-open case this
    // file has to prove, and one table has to carry both populations.
    Schema::create('enforcement_users', function (Blueprint $table): void {
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

    Schema::create('statusless_enforcement_users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
    });

    // UpdateUserAction snapshots the persisted role set on every change.
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('permissions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

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

    // Passport tables, copied from the migrations the kit publishes
    // (stubs/database/migrations/2026_03_04_2051*), UUID owner/user keys
    // included.
    Schema::create('oauth_clients', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->nullableUuidMorphs('owner');
        $table->string('name');
        $table->string('secret')->nullable();
        $table->string('provider')->nullable();
        $table->text('redirect_uris');
        $table->text('grant_types');
        $table->boolean('revoked');
        $table->timestamps();
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

    Schema::create('oauth_auth_codes', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->uuid('user_id')->index();
        $table->uuid('client_id');
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->dateTime('expires_at')->nullable();
    });

    Schema::create('oauth_device_codes', function (Blueprint $table): void {
        $table->char('id', 80)->primary();
        $table->uuid('user_id')->nullable()->index();
        $table->uuid('client_id')->index();
        $table->char('user_code', 8)->unique();
        $table->text('scopes');
        $table->boolean('revoked');
        $table->dateTime('user_approved_at')->nullable();
        $table->dateTime('last_polled_at')->nullable();
        $table->dateTime('expires_at')->nullable();
    });

    DB::table('oauth_clients')->insert([
        'id' => ENFORCEMENT_CLIENT_ID,
        'owner_type' => null,
        'owner_id' => null,
        'name' => 'Enforcement test client',
        'secret' => null,
        // Matches auth.providers.users, so HasApiTokens::tokens() keeps the
        // token inside this account's provider scope.
        'provider' => 'users',
        'redirect_uris' => '[]',
        'grant_types' => '["password"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app()->instance(RoleSelectOptionsQuery::class, new class extends RoleSelectOptionsQuery
    {
        public function get(User $user): array
        {
            return [];
        }
    });

    // Stateless bearer guard in Passport's shape — see the file header.
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

            return $token === null ? null : $provider->retrieveById($token->user_id);
        },
        request(),
        Auth::createUserProvider($config['provider']),
    ));

    Route::get('/sk-login', fn () => 'login page')->name('login');

    Route::middleware([StartSession::class, EnsureUserIsActive::class])
        ->get('/enforcement-panel', fn () => 'panel');

    Route::middleware([EnsureUserIsActive::class])
        ->get('/api/enforcement', fn () => response()->json([
            'user_id' => Auth::guard('api')->id(),
        ]));

    Route::getRoutes()->refreshNameLookups();
});

// ──────────────────────────────────────────────────────────────────────────────
// 1. The cut — web session
// ──────────────────────────────────────────────────────────────────────────────

it('cuts an open web session on the next request after the account is deactivated', function (): void {
    $user = enforcementUser();
    enforcementLogin($user);

    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    enforcementUpdate($user, ['status' => 'inactive']);

    enforcementNextRequest();

    $this->get('/enforcement-panel')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __(EnsureUserIsActive::MESSAGE_KEY));

    // The credential itself is gone, not just this response: the session no
    // longer holds the login key, so replaying the same cookie is a guest.
    expect(session()->has(Auth::guard('web')->getName()))->toBeFalse()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. The cut — OAuth token
// ──────────────────────────────────────────────────────────────────────────────

it('answers a deactivated account\'s live OAuth token with the 403 envelope, not a 200', function (): void {
    // An account disabled BEFORE this release: the status change never ran
    // through RevokeUserAccessAction, so the token is still live. The
    // middleware is the backstop, and it must not serve the route.
    $user = enforcementUser(['status' => 'inactive']);
    $tokenId = enforcementCredentials($user);

    $this->withToken($tokenId)
        ->getJson('/api/enforcement')
        ->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'status' => 403,
            'message' => __(EnsureUserIsActive::MESSAGE_KEY),
            'data' => null,
        ]);
});

it('leaves the revoked token unable to authenticate at all', function (): void {
    $user = enforcementUser();
    $tokenId = enforcementCredentials($user);

    enforcementNextRequest();
    $this->withToken($tokenId)->getJson('/api/enforcement')
        ->assertOk()
        ->assertJson(['user_id' => $user->getKey()]);

    enforcementUpdate($user, ['status' => 'inactive']);

    enforcementNextRequest();
    $this->withToken($tokenId)->getJson('/api/enforcement')
        ->assertOk()
        ->assertJson(['user_id' => null]);
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. Backward compatibility — nobody else is cut
// ──────────────────────────────────────────────────────────────────────────────

it('keeps an account with a null status working and keeps its credentials', function (): void {
    $user = enforcementUser(['status' => null]);
    $tokenId = enforcementCredentials($user);
    enforcementLogin($user);

    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    // A null status is not a denied status, so an edit through the production
    // write path must not take the account's credentials with it.
    enforcementUpdate($user, ['first_name' => 'Grace']);

    enforcementNextRequest();
    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    expect(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
});

it('keeps an account whose status the kit does not recognise working', function (): void {
    // `pending_review` is a value some other app's vocabulary produces. The
    // middleware can only ever block a status the operator listed.
    $user = enforcementUser(['status' => 'pending_review']);
    $tokenId = enforcementCredentials($user);
    enforcementLogin($user);

    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    enforcementUpdate($user, ['status' => 'pending_review', 'first_name' => 'Grace']);

    enforcementNextRequest();
    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    expect(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
});

it('lets a user model that has no status attribute through', function (): void {
    config(['auth.providers.users.model' => StatuslessEnforcementUser::class]);
    enforcementNextRequest();

    $user = StatuslessEnforcementUser::create([
        'id' => (string) Str::uuid(),
        'email' => 'no-status@example.test',
        'password' => 'irrelevant',
    ]);

    enforcementLogin($user);

    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');
});

it('restores pre-release behaviour exactly when enforcement is switched off', function (): void {
    config(['starter-kit.security.enforce_active_status' => false]);

    $user = enforcementUser();
    $tokenId = enforcementCredentials($user);
    enforcementLogin($user);

    enforcementUpdate($user, ['status' => 'inactive']);

    // Request pipeline: unchanged.
    enforcementNextRequest();
    $this->get('/enforcement-panel')->assertOk()->assertSee('panel');

    enforcementNextRequest();
    $this->withToken($tokenId)->getJson('/api/enforcement')
        ->assertOk()
        ->assertJson(['user_id' => $user->getKey()]);

    // Credentials: untouched. The switch is shared with the revocation half on
    // purpose — one key turns the whole feature off.
    expect(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. Revocation — once on the transition, never otherwise
// ──────────────────────────────────────────────────────────────────────────────

it('revokes every credential class exactly once on the deactivating transition', function (): void {
    $user = enforcementUser();
    $tokenId = enforcementCredentials($user);

    Log::spy();

    $revoker = new CountingRevokeUserAccessAction;
    enforcementUpdate($user, ['status' => 'inactive'], $revoker);

    expect($revoker->calls)->toBe(1)
        ->and($revoker->scheduled)->toBe(1)
        ->and(enforcementCredentialsRevoked($user, $tokenId))->toBeTrue();

    // One structured line, so the revocation ran once — not once per credential
    // class and not once per save.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_contains($message, 'credentials revoked'))
        ->once();
});

it('revokes nothing on an edit that does not change the status', function (string $status): void {
    $user = enforcementUser(['status' => $status]);
    $tokenId = enforcementCredentials($user);

    $revoker = new CountingRevokeUserAccessAction;
    enforcementUpdate($user, ['first_name' => 'Grace'], $revoker);

    expect($user->refresh()->first_name)->toBe('Grace')
        ->and($revoker->calls)->toBe(1)
        ->and($revoker->scheduled)->toBe(0)
        ->and(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
})->with([
    // Renaming an account that has been inactive for a year is not a
    // transition — this is the case that would otherwise revoke on every save.
    'already inactive' => 'inactive',
    'active' => 'active',
]);

it('revokes nothing when the account is re-activated', function (): void {
    $user = enforcementUser(['status' => 'inactive']);
    $tokenId = enforcementCredentials($user);

    $revoker = new CountingRevokeUserAccessAction;
    enforcementUpdate($user, ['status' => 'active'], $revoker);

    expect($revoker->scheduled)->toBe(0)
        ->and(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
});

it('revokes nothing when the surrounding transaction is rolled back', function (): void {
    $user = enforcementUser();
    $tokenId = enforcementCredentials($user);

    $revoker = new CountingRevokeUserAccessAction;

    try {
        DB::transaction(function () use ($user, $revoker): void {
            enforcementUpdate($user, ['status' => 'inactive'], $revoker);

            throw new RuntimeException('outer pipeline failed');
        });
    } catch (RuntimeException) {
        // Expected: an ActionPipeline step after this one blew up.
    }

    // The revocation was SCHEDULED but must never have run: the status change
    // it was reacting to no longer exists.
    expect($revoker->scheduled)->toBe(1)
        ->and(DB::table('enforcement_users')->where('id', $user->getKey())->value('status'))->toBe('active')
        ->and(enforcementCredentialsLive($user, $tokenId))->toBeTrue();
});
