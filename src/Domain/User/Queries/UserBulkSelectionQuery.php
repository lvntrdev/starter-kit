<?php

namespace Lvntr\StarterKit\Domain\User\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;
use Lvntr\StarterKit\Support\BulkFilterSnapshot;

/**
 * Query: Resolve the full set of users matching a "select all filtered"
 * (cross-page) bulk request.
 *
 * Security contract — read before touching:
 *
 *   1. ROLE HIERARCHY. The base query goes through
 *      UserDatatableQuery::applyVisibilityScope() — the exact same scope the
 *      datatable listing uses. This is non-negotiable: without it, "select all
 *      filtered" would reach users the actor cannot even see in the table,
 *      bypassing the hierarchy filter (a privilege-escalation hole). The scope
 *      lives in ONE place so the two paths cannot diverge.
 *
 *   2. ALLOW-LISTED FILTERS, FAIL-CLOSED. Only the filters the datatable
 *      actually exposes (status, role, search, created_at_from, created_at_to)
 *      are honoured, and they are applied with the datatable's own semantics —
 *      the date bounds go through DatatableQueryBuilder::applyCalendarDateRange(),
 *      the SAME predicate the table renders with, so the visible set and the
 *      bulk set stay equal across timezone/DST boundaries. A hostile snapshot
 *      cannot inject extra WHERE clauses. Crucially, an ACTIVE filter this
 *      query cannot apply is NOT silently dropped — BulkFilterSnapshot rejects
 *      the request with a 422, because dropping it would resolve a set WIDER
 *      than the one the user saw and delete rows the filter was hiding.
 *
 *   3. DEFENCE IN DEPTH. This query narrows the candidate set; it does NOT
 *      replace per-item authorization. The collection it returns is still run
 *      through BulkDeleteUserAction::authorize() by the dispatcher, which
 *      re-checks permission + hierarchy + self-delete per row. This query is an
 *      ADDITIONAL gate, never a bypass of the per-item one.
 *
 *   4. PERFORMANCE BOUND. Cross-page selection is not bounded by the
 *      ids.max:500 request rule (there are no ids). A hard upper limit caps how
 *      many rows a single bulk operation can touch, protecting the request from
 *      unbounded memory/transaction size on very large tables.
 */
class UserBulkSelectionQuery
{
    /**
     * Hard upper bound on rows a single cross-page bulk operation may resolve.
     * Mirrors the spirit of the ids.max:500 request rule, scaled up for the
     * cross-page case while still protecting against unbounded batches.
     */
    public const MAX_ITEMS = 5000;

    /**
     * Filter keys the datatable exposes — anything else in the snapshot is
     * ignored. Keep in lockstep with UserDatatableQuery's filterable()/
     * searchable() definitions.
     *
     * @var string[]
     */
    private const ALLOWED_FILTERS = ['status', 'role', 'search', 'created_at_from', 'created_at_to'];

    /**
     * Resolve the users matching the snapshot, scoped to what the actor may
     * manage, eager-loading roles for the per-item authorization step.
     *
     * @param  array<string, mixed>  $filterSnapshot  Raw client snapshot; keys
     *                                                may be bracket-style
     *                                                ('filter[status]') or flat.
     * @return Collection<int, User>
     */
    public function resolve(User $actor, array $filterSnapshot): Collection
    {
        $query = UserDatatableQuery::applyVisibilityScope(User::query(), $actor);

        $this->applyFilters($query, $this->normalizeFilters($filterSnapshot));

        return $query
            ->with('roles')
            // Deterministic subset: order before capping so MAX_ITEMS always
            // takes the SAME first N rows (an unordered limit could otherwise
            // drop a different, arbitrary slice between identical requests).
            ->orderBy('id')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /**
     * Flatten the snapshot into an allow-listed key => value filter map.
     *
     * Delegates to the shared BulkFilterSnapshot normalizer so User and Role
     * bulk selection enforce the SAME fail-closed rule (an active filter this
     * query cannot apply raises a 422 instead of being dropped).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function normalizeFilters(array $snapshot): array
    {
        return BulkFilterSnapshot::normalize($snapshot, self::ALLOWED_FILTERS);
    }

    /**
     * Apply the allow-listed filters using the same semantics as the datatable.
     *
     * @param  Builder<User>  $query
     * @param  array<string, string>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status'])) {
            // Mirrors AllowedFilter::exact('status'). Known fail-safe delta:
            // Spatie's exact filter splits a comma-separated value into a
            // whereIn(), whereas this literal where() treats it as one string.
            // The datatable's status filter is single-value, so this never
            // diverges in practice — and if it ever did, this direction matches
            // FEWER rows (never more), so the per-item authorize() gate is never
            // widened. Safe by design.
            $query->where('status', $filters['status']);
        }

        if (isset($filters['role'])) {
            // Mirrors the 'role' AllowedFilter::callback in the datatable.
            $query->whereHas('roles', fn (Builder $r) => $r->where('name', $filters['role']));
        }

        // Mirrors ...DatatableQueryBuilder::dateRangeFilters('created_at') on the
        // table: SAME helper, so the local-calendar/timezone boundaries are
        // identical and the bulk set cannot drift from the visible set. A
        // malformed date is ignored here exactly as the datatable ignores it.
        DatatableQueryBuilder::applyCalendarDateRange(
            $query,
            'created_at',
            $filters['created_at_from'] ?? null,
            $filters['created_at_to'] ?? null,
        );

        if (isset($filters['search'])) {
            // Mirrors DatatableQueryBuilder's 'search' filter: each word must
            // match at least one searchable column; wildcards are escaped.
            $words = array_filter(explode(' ', trim($filters['search'])));
            $fields = ['id', 'first_name', 'last_name', 'email'];

            $query->where(function (Builder $outer) use ($words, $fields) {
                foreach ($words as $word) {
                    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $word);
                    $outer->where(function (Builder $inner) use ($escaped, $fields) {
                        foreach ($fields as $field) {
                            $inner->orWhere($field, 'like', '%'.$escaped.'%');
                        }
                    });
                }
            });
        }
    }
}
