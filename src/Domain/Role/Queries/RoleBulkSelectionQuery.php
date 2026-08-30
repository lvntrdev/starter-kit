<?php

namespace Lvntr\StarterKit\Domain\Role\Queries;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;
use Lvntr\StarterKit\Support\BulkFilterSnapshot;

/**
 * Query: Resolve the full set of roles matching a "select all filtered"
 * (cross-page) bulk request.
 *
 * Security contract — read before touching:
 *
 *   1. NO VISIBILITY SCOPE BY DESIGN. Unlike users, the role datatable
 *      (RoleDatatableQuery) lists ALL roles to any actor with roles.read; the
 *      rank hierarchy is enforced PER ITEM at delete time
 *      (BulkDeleteRoleAction::authorize() — system roles protected, and a
 *      non-system_admin may only delete roles ranked below their own). So this
 *      cross-page query intentionally has no base hierarchy filter: it returns
 *      the filtered candidate set, and the dispatcher's per-item authorize is
 *      the gate that drops protected/outranking roles. This matches exactly
 *      what the ids-based path already does.
 *
 *   2. ALLOW-LISTED FILTERS, FAIL-CLOSED. Only the search the datatable exposes
 *      is honoured (RoleDatatableQuery declares searchable(['id', 'name']) and
 *      no filterable()), applied through DatatableQueryBuilder::applySearchWords()
 *      — the SAME helper the table's own search callback runs, so the visible
 *      set and the bulk set cannot drift. An ACTIVE filter this query cannot apply is NOT
 *      silently dropped — BulkFilterSnapshot rejects the request with a 422,
 *      because dropping it would resolve a set WIDER than the one the user saw
 *      and delete roles the filter was hiding.
 *
 *   3. PERFORMANCE BOUND. No ids.max:500 applies to cross-page; a hard cap
 *      protects against unbounded batches (the role table is small in practice,
 *      but the bound is kept for symmetry and safety).
 */
class RoleBulkSelectionQuery
{
    /** Hard upper bound on rows a single cross-page bulk operation may resolve. */
    public const MAX_ITEMS = 5000;

    /**
     * Resolve the roles matching the snapshot.
     *
     * No actor argument by design — the role listing has no query-time hierarchy
     * scope (every roles.read actor sees all roles); the rank hierarchy is
     * enforced per item by BulkDeleteRoleAction::authorize(), so the actor is
     * not needed here (see the class docblock).
     *
     * @param  array<string, mixed>  $filterSnapshot
     * @return Collection<int, Role>
     */
    public function resolve(array $filterSnapshot): Collection
    {
        $query = Role::query();

        $search = $this->extractSearch($filterSnapshot);
        if ($search !== null) {
            // Keep the column list in lockstep with RoleDatatableQuery::searchable().
            DatatableQueryBuilder::applySearchWords($query, ['id', 'name'], $search);
        }

        // Deterministic subset: order before capping so MAX_ITEMS always takes
        // the SAME first N rows (an unordered limit could otherwise drop a
        // different, arbitrary slice between identical requests).
        return $query->orderBy('id')->limit(self::MAX_ITEMS)->get();
    }

    /**
     * Pull the (allow-listed) search term out of the client snapshot, accepting
     * both bracket-style ('filter[search]') and nested ['filter']['search']
     * shapes. Non-filter keys (sort/page/columns/...) are ignored; any OTHER
     * active filter raises a 422 through the shared normalizer. A literal
     * `true` / `false` comes back as the boolean Spatie hands the table
     * (BulkFilterSnapshot contract #4).
     *
     * @param  array<string, mixed>  $snapshot
     *
     * @throws ValidationException
     */
    private function extractSearch(array $snapshot): string|bool|null
    {
        return BulkFilterSnapshot::normalize($snapshot, ['search'])['search'] ?? null;
    }
}
