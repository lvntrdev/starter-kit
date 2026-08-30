<?php

/*
|--------------------------------------------------------------------------
| ApiToken/ApiClient dtApi() — built-in ID header must accept `sort=id`
|--------------------------------------------------------------------------
|
| The API tokens / API clients tabs ship a built-in ID column header that is
| sortable by default. Before this fix neither controller's sortable()
| allow-list carried 'id', so clicking that header produced Spatie's
| InvalidSortQuery (400) on both screens. This locks both dtApi() endpoints
| accepting `sort=id` and `sort=-id` with a 200 and id-ordered rows.
|
| oauth_* tables are built inline the same way ApiClientAuditTest does;
| App\Models\User is required from the stub the same way, because
| ApiTokenResource::collection() prefetches users via that FQCN.
|
*/

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Http\Controllers\Admin\ApiClientController;
use Lvntr\StarterKit\Http\Controllers\Admin\ApiTokenController;
use Spatie\QueryBuilder\QueryBuilderRequest;

if (! class_exists(User::class)) {
    require_once dirname(__DIR__, 3).'/stubs/app/Models/User.php';
}

beforeEach(function (): void {
    Schema::create('oauth_clients', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->nullableMorphs('owner');
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
        $table->foreignId('user_id')->nullable()->index();
        $table->uuid('client_id');
        $table->string('name')->nullable();
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->timestamps();
        $table->dateTime('expires_at')->nullable();
    });

    app()->bind(
        QueryBuilderRequest::class,
        fn (): QueryBuilderRequest => QueryBuilderRequest::fromRequest(request()),
    );

    Gate::before(fn () => true);
});

afterEach(function (): void {
    Schema::dropIfExists('oauth_access_tokens');
    Schema::dropIfExists('oauth_clients');
});

function idSortAllowListActor(): Authenticatable
{
    $id = DB::table('users')->insertGetId([
        'name' => 'Admin',
        'email' => 'id-sort-actor@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $actor = new class extends AuthUser
    {
        protected $table = 'users';

        protected $guarded = [];

        public $timestamps = false;
    };

    return $actor->forceFill(['id' => $id]);
}

function seedIdSortOauthClient(): string
{
    $clientId = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $clientId,
        'name' => 'ID sort client',
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => '["personal_access"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $clientId;
}

it('accepts sort=id and sort=-id on the API tokens datatable', function (): void {
    $actor = idSortAllowListActor();
    test()->actingAs($actor);
    request()->setUserResolver(fn () => $actor);

    $clientId = seedIdSortOauthClient();

    foreach (['token-b', 'token-a', 'token-c'] as $tokenId) {
        DB::table('oauth_access_tokens')->insert([
            'id' => $tokenId,
            'user_id' => null,
            'client_id' => $clientId,
            'name' => $tokenId,
            'scopes' => '[]',
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
    }

    request()->merge(['sort' => 'id']);
    $ascending = app(ApiTokenController::class)->dtApi(request())
        ->toResponse(request())->getData(true);

    expect($ascending['data']['data'])->not->toBeEmpty();
    $ascendingIds = array_column($ascending['data']['data'], 'id');
    $sorted = $ascendingIds;
    sort($sorted);
    expect($ascendingIds)->toBe($sorted);

    request()->merge(['sort' => '-id']);
    $descending = app(ApiTokenController::class)->dtApi(request())
        ->toResponse(request())->getData(true);

    $descendingIds = array_column($descending['data']['data'], 'id');
    $sortedDesc = $descendingIds;
    rsort($sortedDesc);
    expect($descendingIds)->toBe($sortedDesc);
});

it('accepts sort=id and sort=-id on the API clients datatable', function (): void {
    test()->actingAs(idSortAllowListActor());

    seedIdSortOauthClient();
    seedIdSortOauthClient();
    seedIdSortOauthClient();

    request()->merge(['sort' => 'id']);
    $ascending = app(ApiClientController::class)->dtApi(request())
        ->toResponse(request())->getData(true);

    expect($ascending['data']['data'])->not->toBeEmpty();
    $ascendingIds = array_column($ascending['data']['data'], 'id');
    $sorted = $ascendingIds;
    sort($sorted);
    expect($ascendingIds)->toBe($sorted);

    request()->merge(['sort' => '-id']);
    $descending = app(ApiClientController::class)->dtApi(request())
        ->toResponse(request())->getData(true);

    $descendingIds = array_column($descending['data']['data'], 'id');
    $sortedDesc = $descendingIds;
    rsort($sortedDesc);
    expect($descendingIds)->toBe($sortedDesc);
});
