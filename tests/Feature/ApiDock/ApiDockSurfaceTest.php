<?php

/*
|--------------------------------------------------------------------------
| ApiDock surface — access gate regression (Task 3's CheckApiDocsAccess)
|--------------------------------------------------------------------------
|
| Route-registration coverage (Scramble's default docs/api no longer
| registers, api-dock's own routes do) lives in ScrambleBridgeTest.php in
| this same directory. This file locks the OTHER half of Task 3: the panel
| is closed to a user without the seeded `api-docs.read` ability and open to
| one who has it.
|
| The real panel route (`api-dock.docs`, registered by the actual
| ApiDockServiceProvider) is hit over HTTP rather than calling the
| middleware in isolation, and `api-dock.middleware` is set to the exact
| stack stubs/config/api-dock.php ships (['web', 'auth',
| CheckApiDocsAccess::class]) — a passing assertion here means the real
| wiring works, not a stand-in for it.
|
*/

use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;
use Lvntr\StarterKit\Tests\Feature\ApiDock\ApiDockAccessGateTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

uses(ApiDockAccessGateTestCase::class);

// stubs/app is not autoloaded for the package test suite (no real consumer
// app skeleton exists here) — same guarded require_once pattern as
// PasswordExpiryTest / AuthFeatureGatingTest.
require_once dirname(__DIR__, 3).'/stubs/app/Http/Middleware/CheckApiDocsAccess.php';

class ApiDockGateTestUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    /** Spatie permissions below are seeded on the default 'web' guard. */
    protected string $guard_name = 'web';
}

// StarterKitServiceProvider::configureScramble() type-hints the `viewApiDocs`
// Gate closure's parameter as App\Models\User (the real app namespace this
// kit assumes exists); the package suite carries no such class, so it is
// aliased to the local test actor — same guarded pattern as App\Models\Setting
// in AuthFeatureGatingTest.
if (! class_exists(User::class)) {
    class_alias(ApiDockGateTestUser::class, User::class);
}

function apiDockGateUser(): ApiDockGateTestUser
{
    $user = new ApiDockGateTestUser;
    $user->forceFill([
        'name' => 'API Dock Probe',
        'email' => uniqid('api-dock-', true).'@x.test',
        'password' => 'x',
    ])->save();

    return $user;
}

it('closes the api-dock panel to a user without the seeded api-docs.read ability', function (): void {
    $user = apiDockGateUser(); // no permission granted

    $this->actingAs($user)
        ->get(route('api-dock.docs'))
        ->assertForbidden();
});

it('opens the api-dock panel to a user with the seeded api-docs.read ability', function (): void {
    Permission::findOrCreate('api-docs.read');
    $user = apiDockGateUser();
    $user->givePermissionTo('api-docs.read');

    $this->actingAs($user)
        ->get(route('api-dock.docs'))
        ->assertOk();
});
