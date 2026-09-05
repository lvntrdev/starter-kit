<?php

/*
|--------------------------------------------------------------------------
| OAuth credential revocation — the pair on logout, the account-wide sweep
|--------------------------------------------------------------------------
|
| tests/Feature/User/ActiveStatusEnforcementTest.php already pins the FULL
| account-wide sweep end to end (access + refresh + auth code + device code,
| through the admin write path). THIS file is narrower and reproduces the two
| SK-AUD-002 findings directly:
|
|   1. LogoutUserAction must drop the refresh token bound to the access token
|      it just revoked, not only the access token — a refresh token
|      deliberately OUTLIVES the access token it was issued with, so revoking
|      the access token alone leaves a credential that mints a new one.
|
|   2. RevokeUserAccessAction's account-wide sweep must not filter the access
|      token id set by `revoked`. The pre-fix code fed only NON-REVOKED access
|      token ids into the refresh-token whereIn, so a refresh token bound to an
|      access token that was ALREADY revoked — by a prior logout, by Passport's
|      own refresh-grant rotation, by anything — was invisible to the sweep and
|      stayed live on a disabled account.
|
| Both actions are exercised directly, against the shipped consumer User model
| pointed at a table this file owns — same technique ActiveStatusEnforcementTest
| uses, for the same reason: the shim `users` table DatabaseTestCase builds has
| no `status`/uuid columns, and RevokeUserAccessAction's afterCommit() call
| needs a real connection name off a real Eloquent model.
|
*/

use App\Domain\Auth\Actions\LogoutUserAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Lvntr\StarterKit\Domain\User\Actions\RevokeUserAccessAction;
use Spatie\Permission\PermissionRegistrar;

if (! class_exists(User::class)) {
    require_once dirname(__DIR__, 3).'/stubs/app/Models/User.php';
}

if (! class_exists(LogoutUserAction::class)) {
    require_once dirname(__DIR__, 3).'/stubs/app/Domain/Auth/Actions/LogoutUserAction.php';
}

/**
 * The shipped consumer User model, pointed at a table this file owns — see
 * ActiveStatusEnforcementTest's EnforcementUser for why a dedicated table is
 * necessary (UUID key, `status` column, HasApiTokens/HasRoles boot needs).
 */
class OAuthRevocationUser extends User
{
    protected $table = 'oauth_revocation_users';
}

const OAUTH_REVOCATION_CLIENT_ID = '9d0f2222-3333-4444-8555-666677778888';

function oauthRevocationUser(array $attributes = []): OAuthRevocationUser
{
    return OAuthRevocationUser::create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-oauth@example.test',
        'password' => 'Valid-Password-1!',
        'status' => 'active',
    ], $attributes));
}

/**
 * Seed one access token + its bound refresh token, independently revocable.
 */
function oauthRevocationCredentials(OAuthRevocationUser $user, bool $accessRevoked, bool $refreshRevoked): string
{
    $tokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->getKey(),
        'client_id' => OAUTH_REVOCATION_CLIENT_ID,
        'name' => 'test token',
        'scopes' => '[]',
        'revoked' => $accessRevoked,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $tokenId,
        'revoked' => $refreshRevoked,
        'expires_at' => now()->addDays(14),
    ]);

    return $tokenId;
}

function oauthAccessRevoked(string $tokenId): bool
{
    return (bool) DB::table('oauth_access_tokens')->where('id', $tokenId)->value('revoked');
}

function oauthRefreshRevoked(string $tokenId): bool
{
    return (bool) DB::table('oauth_refresh_tokens')->where('access_token_id', $tokenId)->value('revoked');
}

beforeEach(function (): void {
    config(['activitylog.enabled' => false]);
    config(['auth.providers.users.model' => OAuthRevocationUser::class]);
    config(['permission' => require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/config/permission.php']);
    app()->forgetInstance(PermissionRegistrar::class);

    Schema::create('oauth_revocation_users', function (Blueprint $table): void {
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

    DB::table('oauth_clients')->insert([
        'id' => OAUTH_REVOCATION_CLIENT_ID,
        'owner_type' => null,
        'owner_id' => null,
        'name' => 'OAuth revocation test client',
        'secret' => null,
        'provider' => 'users',
        'redirect_uris' => '[]',
        'grant_types' => '["password"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// 1. LogoutUserAction revokes the PAIR
// ──────────────────────────────────────────────────────────────────────────────

it('revokes the access token AND the refresh token bound to it on logout', function (): void {
    $user = oauthRevocationUser();
    $tokenId = oauthRevocationCredentials($user, accessRevoked: false, refreshRevoked: false);

    $user->withAccessToken(new AccessToken(['oauth_access_token_id' => $tokenId]));

    (new LogoutUserAction)->execute($user);

    // The assertion that catches the pre-fix code: it revoked ONLY the access
    // token (a single call), so `oauthRefreshRevoked()` would still read false.
    expect(oauthAccessRevoked($tokenId))->toBeTrue()
        ->and(oauthRefreshRevoked($tokenId))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. RevokeUserAccessAction sweeps a refresh token whose access token is
//    ALREADY revoked — the exact gap the report reproduced
// ──────────────────────────────────────────────────────────────────────────────

it('revokes a live refresh token bound to an already-revoked access token on the account-wide sweep', function (): void {
    $user = oauthRevocationUser();

    // The state a prior logout (or a Passport refresh-grant rotation) leaves
    // behind: the access token is gone, the refresh token bound to it is not.
    $tokenId = oauthRevocationCredentials($user, accessRevoked: true, refreshRevoked: false);

    (new RevokeUserAccessAction)->execute($user, 'active', 'inactive');

    // The assertion that catches the pre-fix code: revocableTokenIds() used to
    // filter access token ids by where('revoked', false), so an
    // already-revoked access token id never reached the refresh-token
    // whereIn(), and this would still read false.
    expect(oauthRefreshRevoked($tokenId))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. Reactivation resurrects nothing
// ──────────────────────────────────────────────────────────────────────────────

it('leaves a revoked token revoked when the account is reactivated', function (): void {
    $user = oauthRevocationUser(['status' => 'inactive']);
    $tokenId = oauthRevocationCredentials($user, accessRevoked: true, refreshRevoked: true);

    $scheduled = (new RevokeUserAccessAction)->execute($user, 'inactive', 'active');

    expect($scheduled)->toBeFalse()
        ->and(oauthAccessRevoked($tokenId))->toBeTrue()
        ->and(oauthRefreshRevoked($tokenId))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. No throw when there is nothing Passport-shaped to revoke
// ──────────────────────────────────────────────────────────────────────────────

it('runs logout without throwing when the oauth tables are not wired up', function (): void {
    $user = oauthRevocationUser();
    $user->withAccessToken(new AccessToken(['oauth_access_token_id' => Str::random(80)]));

    // The closest in-process proxy for "an install without Passport": Passport
    // itself is a hard dev dependency of this suite and cannot be unloaded, but
    // an install that never migrated the oauth tables hits the exact same
    // try/catch in RevokesOAuthCredentials::revokeCurrentOAuthCredentials() —
    // the UPDATE throws, the trait logs a warning and returns false, and the
    // caller never sees an exception.
    Schema::dropIfExists('oauth_access_tokens');
    Schema::dropIfExists('oauth_refresh_tokens');

    expect(fn () => (new LogoutUserAction)->execute($user))->not->toThrow(Throwable::class);
});
