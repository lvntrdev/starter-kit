<?php

/*
|--------------------------------------------------------------------------
| Bulk selection parity — visible datatable id set == bulk candidate id set
|--------------------------------------------------------------------------
|
| Rapor'un 1. kabul kriteri: bir tarih-aralığı filtresi altında datatable'ın
| gösterdiği id kümesi ile "select all filtered" (cross-page) bulk akışının
| adayı olarak çözdüğü id kümesi, TZ/DST senaryolarında dahi HER ZAMAN eşit
| olmalı — aksi halde ya kullanıcı görmediği bir satırı toplu silebilir ya da
| gördüğü bir satır toplu işlemin dışında kalır.
|
| Her iki taraf da SAME helper üzerinden gider:
|   - Datatable: DatatableQueryBuilder::dateRangeFilters('created_at')
|   - Bulk:      UserBulkSelectionQuery::applyFilters() → aynı
|                DatatableQueryBuilder::applyCalendarDateRange() çağrısı
|
| App\Models\User pakette autoload edilmediğinden UserBulkSelectionQuery'nin
| normalizeFilters()/applyFilters() adımları burada reflection ile, yerel bir
| Authenticatable test modelinin Builder'ı üzerinde çağrılır (mevcut
| BulkSelectionQueryTest'teki yaklaşımla aynı desen).
|
| 422 sözleşmesi de aynı dosyada kilitlenir: yalnız `filter` anahtarları
| aktif-bilinmeyen ise reddedilir; `sort`/`page`/`columns`/`type` gibi
| filtre-olmayan anahtarlar asla tetiklemez.
|
*/

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Validation\ValidationException;
use Lvntr\StarterKit\Domain\User\Queries\UserBulkSelectionQuery;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;
use Spatie\QueryBuilder\QueryBuilderRequest;

class BulkParityTestUser extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function (): void {
    app()->bind(
        QueryBuilderRequest::class,
        fn (): QueryBuilderRequest => QueryBuilderRequest::fromRequest(request()),
    );

    config()->set('app.timezone', 'UTC');
    config()->set('app.display_timezone', 'UTC');
});

function createBulkParityUser(string $email, string $createdAt, ?string $timezone = null): BulkParityTestUser
{
    return BulkParityTestUser::query()->create([
        'name' => $email,
        'email' => $email,
        'timezone' => $timezone,
        'password' => 'secret-hash',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * @param  array{from?: string, to?: string}  $range
 * @return list<int>
 */
function bulkParityDatatableIds(array $range): array
{
    request()->replace([
        'filter' => array_filter([
            'created_at_from' => $range['from'] ?? null,
            'created_at_to' => $range['to'] ?? null,
        ], fn (?string $value): bool => $value !== null),
    ]);

    $payload = DatatableQueryBuilder::for(BulkParityTestUser::class)
        ->filterable(DatatableQueryBuilder::dateRangeFilters('created_at'))
        ->sortable(['id'])
        ->defaultSort('id')
        ->response()
        ->toResponse(request())
        ->getData(true);

    return array_map('intval', array_column($payload['data']['data'], 'id'));
}

/**
 * @param  array{from?: string, to?: string}  $range
 * @return list<int>
 */
function bulkParityCandidateIds(array $range): array
{
    $snapshot = array_filter([
        'filter[created_at_from]' => $range['from'] ?? null,
        'filter[created_at_to]' => $range['to'] ?? null,
    ], fn (?string $value): bool => $value !== null);

    $query = new UserBulkSelectionQuery;

    $normalize = new ReflectionMethod($query, 'normalizeFilters');
    $normalize->setAccessible(true);
    $filters = $normalize->invoke($query, $snapshot);

    $apply = new ReflectionMethod($query, 'applyFilters');
    $apply->setAccessible(true);

    $builder = BulkParityTestUser::query();
    $apply->invoke($query, $builder, $filters);

    return $builder->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/**
 * @param  array{from?: string, to?: string}  $range
 */
function assertBulkParity(array $range): void
{
    expect(bulkParityDatatableIds($range))->toBe(bulkParityCandidateIds($range));
}

// ──────────────────────────────────────────────────────────────────────────────
// Parity senaryoları
// ──────────────────────────────────────────────────────────────────────────────

it('keeps parity with a positive-offset local day (Europe/Istanbul)', function (): void {
    config()->set('app.display_timezone', 'Europe/Istanbul');

    createBulkParityUser('inside@example.com', '2026-01-14 21:30:00');
    createBulkParityUser('after@example.com', '2026-01-15 21:00:01');

    assertBulkParity(['from' => '2026-01-15', 'to' => '2026-01-15']);
});

it('keeps parity with a negative-offset local day (America/New_York)', function (): void {
    config()->set('app.display_timezone', 'America/New_York');

    createBulkParityUser('inside@example.com', '2026-01-16 04:30:00');
    createBulkParityUser('after@example.com', '2026-01-16 05:00:00');

    assertBulkParity(['from' => '2026-01-15', 'to' => '2026-01-15']);
});

it('keeps parity when the authenticated user timezone overrides the site setting', function (): void {
    config()->set('app.display_timezone', 'Europe/Istanbul');

    $viewer = createBulkParityUser('viewer@example.com', '2026-08-15 12:00:00', 'America/New_York');
    createBulkParityUser('new-york-day@example.com', '2026-01-16 04:30:00');

    test()->actingAs($viewer);

    assertBulkParity(['from' => '2026-01-15', 'to' => '2026-01-15']);
});

it('keeps parity across a daylight-saving transition day', function (): void {
    config()->set('app.display_timezone', 'America/New_York');

    createBulkParityUser('last-second@example.com', '2026-03-09 03:59:59');
    createBulkParityUser('next-midnight@example.com', '2026-03-09 04:00:00');

    assertBulkParity(['from' => '2026-03-08', 'to' => '2026-03-08']);
});

it('keeps parity with a from-only range', function (): void {
    createBulkParityUser('before@example.com', '2026-01-14 23:59:59');
    createBulkParityUser('after@example.com', '2026-01-15 00:00:00');

    assertBulkParity(['from' => '2026-01-15']);
});

it('keeps parity with a to-only range', function (): void {
    createBulkParityUser('before@example.com', '2026-01-15 23:59:59');
    createBulkParityUser('after@example.com', '2026-01-16 00:00:00');

    assertBulkParity(['to' => '2026-01-15']);
});

it('keeps parity when the date bound is malformed (both sides ignore it)', function (): void {
    createBulkParityUser('kept@example.com', '2026-01-15 12:00:00');

    assertBulkParity(['from' => 'not-a-date', 'to' => '2026-02-30']);
});

// ──────────────────────────────────────────────────────────────────────────────
// 422 sözleşmesi — yalnız `filter` anahtarları aktif-bilinmeyen ise reddedilir
// ──────────────────────────────────────────────────────────────────────────────

it('rejects an active unknown filter key with a filter_snapshot 422 naming it', function (): void {
    $query = new UserBulkSelectionQuery;
    $normalize = new ReflectionMethod($query, 'normalizeFilters');
    $normalize->setAccessible(true);

    try {
        $normalize->invoke($query, ['filter[evil]' => 'x']);
        $caught = null;
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->errors())->toHaveKey('filter_snapshot')
        ->and($caught->errors()['filter_snapshot'][0])->toContain('evil');
});

it('resolves normally when the only unknown key is inactive (empty value)', function (): void {
    $query = new UserBulkSelectionQuery;
    $normalize = new ReflectionMethod($query, 'normalizeFilters');
    $normalize->setAccessible(true);

    $result = $normalize->invoke($query, ['filter[evil]' => '']);

    expect($result)->toBe([]);
});

it('never triggers the 422 on non-filter keys (sort, page, columns, type)', function (): void {
    $query = new UserBulkSelectionQuery;
    $normalize = new ReflectionMethod($query, 'normalizeFilters');
    $normalize->setAccessible(true);

    $result = $normalize->invoke($query, [
        'sort' => '-created_at',
        'page' => '3',
        'columns' => 'name,email',
        'type' => 'export',
    ]);

    expect($result)->toBe([]);
});
