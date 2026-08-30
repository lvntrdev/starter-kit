<?php

/*
|--------------------------------------------------------------------------
| Cross-page "select all filtered" bulk selection — query scope + allow-list
|--------------------------------------------------------------------------
|
| Bu testler Task 4'ün KRİTİK güvenlik garantilerini doğrular:
|
|   A) UserDatatableQuery::scopeByHierarchy() — rol-hiyerarşi görünürlük
|      scope'unun builder çekirdeği. Datatable listesi (applyVisibilityScope)
|      ile cross-page bulk re-query TEK kaynaktan (bu metot) gelir; bu yüzden
|      burada DB üzerinden doğrulanır:
|        - system_admin → tüm kullanıcılar görünür
|        - rütbeli aktör → yalnız eşit/alt rütbe (sort_order >= aktör min)
|        - rolsüz aktör (direct-permission) → yalnız rolsüz kullanıcılar
|
|   B) UserBulkSelectionQuery::normalizeFilters() — allow-list parse + FAIL-CLOSED.
|      Yalnız status/role/search/created_at_from/created_at_to uygulanır;
|      bracket-style ('filter[status]') ve nested (['filter']['status'])
|      şekilleri kabul edilir. Uygulanamayan AKTİF bir filtre sessizce
|      düşürülmez — 422 (ValidationException) ile reddedilir, çünkü düşürmek
|      kullanıcının gördüğünden DAHA GENİŞ bir kümeyi silmeye yol açardı.
|      App\Models gerektirmeyen saf mantık.
|
|   C) RoleBulkSelectionQuery::extractSearch() — yalnız 'search' allow-list'li;
|      başka bir aktif filtre aynı 422'yi doğurur.
|
| App\Models\User/Role bu pakette autoload edilemediğinden resolve()'ın
| DB tarafı (User::query()) burada test edilemez; bunun yerine güvenliği
| belirleyen iki parça izole edilir: (A) scope mantığı yerel bir test modeli
| üzerinden Builder ile, (B/C) allow-list parse reflection ile.
|
*/

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Lvntr\StarterKit\Domain\Role\Queries\RoleBulkSelectionQuery;
use Lvntr\StarterKit\Domain\User\Queries\UserBulkSelectionQuery;
use Lvntr\StarterKit\Domain\User\Queries\UserDatatableQuery;

// ──────────────────────────────────────────────────────────────────────────────
// Yerel test modelleri — App namespace gerektirmez; gerçek roles + pivot şeması
// ──────────────────────────────────────────────────────────────────────────────

class BulkScopeTestRole extends Model
{
    protected $table = 'bulk_scope_roles';

    protected $guarded = [];

    public $timestamps = false;
}

class BulkScopeTestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            BulkScopeTestRole::class,
            'bulk_scope_user_role',
            'user_id',
            'role_id',
        );
    }
}

beforeEach(function (): void {
    Schema::create('bulk_scope_roles', function ($table): void {
        $table->id();
        $table->string('name');
        $table->integer('sort_order');
    });

    Schema::create('bulk_scope_user_role', function ($table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('bulk_scope_user_role');
    Schema::dropIfExists('bulk_scope_roles');
});

/**
 * Helper: create a user row (minimal users schema) and optionally attach roles.
 *
 * @param  int[]  $roleIds
 */
function makeScopedUser(string $email, array $roleIds = []): BulkScopeTestUser
{
    $user = new BulkScopeTestUser;
    $user->forceFill([
        'name' => $email,
        'email' => $email,
        'password' => 'x',
    ])->save();

    if ($roleIds !== []) {
        $user->roles()->attach($roleIds);
    }

    return $user;
}

// ──────────────────────────────────────────────────────────────────────────────
// A) scopeByHierarchy — rol hiyerarşi görünürlük scope'unun builder çekirdeği
// ──────────────────────────────────────────────────────────────────────────────

it('system_admin actor sees every user (scope is a no-op)', function (): void {
    $admin = BulkScopeTestRole::create(['name' => 'admin', 'sort_order' => 1]);
    $user = BulkScopeTestRole::create(['name' => 'user', 'sort_order' => 10]);

    makeScopedUser('a@x.test', [$admin->id]);
    makeScopedUser('b@x.test', [$user->id]);
    makeScopedUser('c@x.test', []);

    $visible = UserDatatableQuery::scopeByHierarchy(
        BulkScopeTestUser::query(),
        true,   // isSystemAdmin
        1,      // minSortOrder (yok sayılır)
    )->pluck('email')->all();

    expect($visible)->toHaveCount(3); // herkes görünür
});

it('ranked actor only sees equal/lower rank users (sort_order >= actor min)', function (): void {
    $admin = BulkScopeTestRole::create(['name' => 'admin', 'sort_order' => 5]);
    $user = BulkScopeTestRole::create(['name' => 'user', 'sort_order' => 10]);

    makeScopedUser('higher@x.test', [$admin->id]);   // sort_order 5 < actor 10 → GİZLİ
    makeScopedUser('equal@x.test', [$user->id]);     // 10 == 10 → görünür
    makeScopedUser('roleless@x.test', []);           // rolsüz → görünür

    $visible = UserDatatableQuery::scopeByHierarchy(
        BulkScopeTestUser::query(),
        false,  // isSystemAdmin
        10,     // actor min sort_order
    )->pluck('email')->sort()->values()->all();

    expect($visible)->toBe(['equal@x.test', 'roleless@x.test']);
});

it('role-less actor (direct-permission) only sees other role-less users', function (): void {
    $admin = BulkScopeTestRole::create(['name' => 'admin', 'sort_order' => 5]);

    makeScopedUser('ranked@x.test', [$admin->id]); // rütbeli → GİZLİ
    makeScopedUser('roleless@x.test', []);         // rolsüz → görünür

    $visible = UserDatatableQuery::scopeByHierarchy(
        BulkScopeTestUser::query(),
        false,  // isSystemAdmin
        null,   // actor min sort_order = null (rolsüz)
    )->pluck('email')->sort()->values()->all();

    expect($visible)->toBe(['roleless@x.test']);
});

// ──────────────────────────────────────────────────────────────────────────────
// B) UserBulkSelectionQuery::normalizeFilters — allow-list + fail-closed
// ──────────────────────────────────────────────────────────────────────────────

function normalizeUserFilters(array $snapshot): array
{
    $q = new UserBulkSelectionQuery;
    $ref = new ReflectionMethod($q, 'normalizeFilters');
    $ref->setAccessible(true);

    return $ref->invoke($q, $snapshot);
}

it('parses bracket-style filter keys and ignores non-filter params', function (): void {
    $result = normalizeUserFilters([
        'filter[status]' => 'active',
        'filter[role]' => 'admin',
        'filter[search]' => 'john',
        'sort' => '-created_at',       // filter değil → yok sayılmalı
        'page' => '3',
        'per_page' => '25',
        'columns' => 'name,email',
    ]);

    expect($result)->toBe([
        'status' => 'active',
        'role' => 'admin',
        'search' => 'john',
    ]);
});

it('parses the nested filter shape too', function (): void {
    $result = normalizeUserFilters([
        'filter' => ['status' => 'inactive', 'role' => 'user'],
    ]);

    expect($result)->toBe([
        'status' => 'inactive',
        'role' => 'user',
    ]);
});

it('parses the created_at date bounds in both shapes', function (): void {
    expect(normalizeUserFilters([
        'filter[created_at_from]' => '2026-01-01',
        'filter[created_at_to]' => '2026-01-31',
    ]))->toBe([
        'created_at_from' => '2026-01-01',
        'created_at_to' => '2026-01-31',
    ]);

    expect(normalizeUserFilters([
        'filter' => ['created_at_from' => '2026-02-01', 'created_at_to' => '2026-02-28'],
    ]))->toBe([
        'created_at_from' => '2026-02-01',
        'created_at_to' => '2026-02-28',
    ]);
});

it('passes a blank / whitespace-only value through verbatim — only null and [] are inactive', function (): void {
    // Spatie's exact filter renders `WHERE status = ''` for a blank value (an
    // empty set), so the bulk side must apply the SAME value rather than drop
    // it — dropping it would resolve a WIDER set than the table showed. null
    // and [] are the only shapes Spatie skips, so they are the only inactive
    // ones. Nothing is trimmed: the table trims nothing either.
    $result = normalizeUserFilters([
        'filter[status]' => '   ',
        'filter[role]' => '',
        'filter[created_at_from]' => null,
        'filter[created_at_to]' => [],
        'filter[search]' => ' kept ',
    ]);

    expect($result)->toBe([
        'status' => '   ',
        'role' => '',
        'search' => ' kept ',
    ]);
});

it('rejects an unknown ACTIVE filter instead of silently dropping it', function (): void {
    expect(fn () => normalizeUserFilters([
        'filter[status]' => 'active',
        'filter[evil]' => 'DROP',
        'sort' => '-created_at',
    ]))->toThrow(ValidationException::class);

    try {
        normalizeUserFilters(['filter[evil]' => 'DROP']);
        $caught = null;
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->errors())->toHaveKey('filter_snapshot')
        ->and($caught->errors()['filter_snapshot'][0])->toContain('evil');
});

it('rejects an unknown active filter in the nested shape as well', function (): void {
    expect(fn () => normalizeUserFilters([
        'filter' => ['status' => 'inactive', 'injected' => 'x'],
    ]))->toThrow(ValidationException::class);
});

it('rejects an allow-listed key whose value is not a usable scalar', function (): void {
    expect(fn () => normalizeUserFilters([
        'filter[role]' => ['array'],
    ]))->toThrow(ValidationException::class);
});

it('rejects an arbitrary / hostile snapshot carrying active filter keys', function (): void {
    expect(fn () => normalizeUserFilters([
        'filter[deleted_at]' => 'whatever',
        'password' => 'x',
        'is_admin' => '1',
    ]))->toThrow(ValidationException::class);
});

it('returns an empty filter set when the snapshot carries no filter key at all', function (): void {
    expect(normalizeUserFilters([
        'password' => 'x',
        'is_admin' => '1',
        'sort' => '-created_at',
    ]))->toBe([]);
});

// ──────────────────────────────────────────────────────────────────────────────
// C) RoleBulkSelectionQuery::extractSearch — yalnız search allow-list'li
// ──────────────────────────────────────────────────────────────────────────────

function extractRoleSearch(array $snapshot): ?string
{
    $q = new RoleBulkSelectionQuery;
    $ref = new ReflectionMethod($q, 'extractSearch');
    $ref->setAccessible(true);

    return $ref->invoke($q, $snapshot);
}

it('extracts the role search term (bracket + nested), ignoring non-filter keys', function (): void {
    expect(extractRoleSearch(['filter[search]' => 'manager', 'sort' => 'id', 'page' => '2']))->toBe('manager');
    expect(extractRoleSearch(['filter' => ['search' => 'editor']]))->toBe('editor');
    // A blank search is an active value that applies nothing on BOTH sides —
    // it is passed through verbatim, not dropped (only null and [] are inactive).
    expect(extractRoleSearch(['filter[search]' => '   ']))->toBe('   ');
    expect(extractRoleSearch(['filter[search]' => null]))->toBeNull();
    expect(extractRoleSearch(['filter[search]' => []]))->toBeNull();
    expect(extractRoleSearch([]))->toBeNull();
});

it('rejects any other active filter on the role snapshot', function (): void {
    expect(fn () => extractRoleSearch(['filter[name]' => 'x']))->toThrow(ValidationException::class);
    expect(fn () => extractRoleSearch(['filter[evil]' => 'y']))->toThrow(ValidationException::class);
});
