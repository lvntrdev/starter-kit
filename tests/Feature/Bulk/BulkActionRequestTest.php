<?php

/*
|--------------------------------------------------------------------------
| BulkActionRequest — `ids` is required in page mode only
|--------------------------------------------------------------------------
|
| The published stub (stubs/app/Http/Requests/Admin/BulkActionRequest.php)
| requires `ids` unless `select_all_filtered` is true: cross-page mode
| re-resolves the set from `filter_snapshot`, so an absent or empty `ids` is
| valid there — while any ids that ARE sent stay shape-checked in both modes
| (array, max:500, opaque strings). The stub is not autoloaded by the package
| suite, so it is required directly and driven through the real FormRequest
| lifecycle (prepareForValidation → authorize → rules).
|
*/

use App\Http\Requests\Admin\BulkActionRequest;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;

require_once dirname(__DIR__, 3).'/stubs/app/Http/Requests/Admin/BulkActionRequest.php';

/**
 * @param  array<string, mixed>  $payload
 */
function makeBulkActionRequest(array $payload): BulkActionRequest
{
    $request = BulkActionRequest::create('/admin/users/bulk', 'POST', $payload);
    $request->setContainer(app())
        ->setRedirector(app(Redirector::class))
        ->setUserResolver(fn () => new AuthUser);

    return $request;
}

/**
 * Validation errors for a payload — an empty array when it passes.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
function bulkActionRequestErrors(array $payload): array
{
    try {
        makeBulkActionRequest($payload)->validateResolved();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// ──────────────────────────────────────────────────────────────────────────────
// Page mode — ids required
// ──────────────────────────────────────────────────────────────────────────────

it('requires ids in page mode (select_all_filtered absent or false)', function (): void {
    foreach ([[], ['select_all_filtered' => false], ['select_all_filtered' => '0']] as $mode) {
        expect(bulkActionRequestErrors(['action' => 'delete'] + $mode))->toHaveKey('ids')
            ->and(bulkActionRequestErrors(['action' => 'delete', 'ids' => []] + $mode))->toHaveKey('ids');
    }
});

it('reports the ids_required message in page mode', function (): void {
    expect(bulkActionRequestErrors(['action' => 'delete'])['ids'])->toBe([__('sk-bulk.ids_required')]);
});

it('accepts a page-mode payload with at least one id and keeps ids as opaque strings', function (): void {
    $request = makeBulkActionRequest(['action' => 'delete', 'ids' => [1, 'a1b2-c3d4', 3]]);

    $request->validateResolved();

    expect($request->validated()['ids'])->toBe(['1', 'a1b2-c3d4', '3']);
});

// ──────────────────────────────────────────────────────────────────────────────
// Cross-page mode — ids optional, still shape-checked when sent
// ──────────────────────────────────────────────────────────────────────────────

it('accepts an absent or empty ids in cross-page mode', function (): void {
    foreach ([true, 1, '1'] as $flag) {
        expect(bulkActionRequestErrors(['action' => 'delete', 'select_all_filtered' => $flag]))->toBe([])
            ->and(bulkActionRequestErrors(['action' => 'delete', 'select_all_filtered' => $flag, 'ids' => []]))->toBe([]);
    }
});

it('still shape-checks ids that are sent in cross-page mode', function (): void {
    $base = ['action' => 'delete', 'select_all_filtered' => true];

    expect(bulkActionRequestErrors($base + ['ids' => array_fill(0, 501, '1')]))->toHaveKey('ids')
        ->and(bulkActionRequestErrors($base + ['ids' => 'not-an-array']))->toHaveKey('ids')
        ->and(bulkActionRequestErrors($base + ['ids' => [str_repeat('x', 65)]]))->toHaveKey('ids.0');
});

it('requires the action in both modes', function (): void {
    expect(bulkActionRequestErrors(['ids' => ['1']]))->toHaveKey('action')
        ->and(bulkActionRequestErrors(['select_all_filtered' => true]))->toHaveKey('action');
});
