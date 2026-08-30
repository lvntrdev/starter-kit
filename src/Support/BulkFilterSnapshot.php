<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support;

use Illuminate\Validation\ValidationException;

/**
 * Normalizer for the client-supplied filter snapshot of a cross-page
 * ("select all filtered") bulk request.
 *
 * Security contract — read before touching:
 *
 *   1. FAIL-CLOSED. A bulk operation is destructive, so the set it resolves
 *      must never be WIDER than the set the user saw in the table. If the
 *      snapshot carries an ACTIVE filter this query cannot apply — an unknown
 *      key with a non-empty value, or an allow-listed key whose value is not a
 *      usable scalar — the request is REJECTED with a 422 instead of silently
 *      dropping the filter (which would delete rows the filter was hiding).
 *
 *   2. ONLY `filter` KEYS ARE FILTERS. The snapshot is built from the table's
 *      full URL query string, so it also carries presentation params (`sort`,
 *      `page`, `per_page`, `columns`) and unrelated page-level params. Those
 *      are not filters and are ignored — they never narrow or widen the set.
 *
 *   3. EMPTY IS INACTIVE. A null, empty or whitespace-only value is not an
 *      active filter (the table itself applies nothing for it), so it is
 *      ignored rather than rejected.
 */
final class BulkFilterSnapshot
{
    /**
     * Flatten + validate the snapshot into an `allow-listed key => trimmed value` map.
     *
     * Accepts both shapes the frontend can produce: bracket-style keys coming
     * from URLSearchParams (`filter[status]`) and an already-nested
     * `['filter' => ['status' => ...]]` array.
     *
     * @param  array<string, mixed>  $snapshot  Raw client snapshot.
     * @param  string[]  $allowed  Filter keys the caller's query can actually apply.
     * @return array<string, string> Keyed in allow-list order.
     *
     * @throws ValidationException When the snapshot carries an active filter the query cannot apply.
     */
    public static function normalize(array $snapshot, array $allowed): array
    {
        $flat = self::flatten($snapshot);

        $unapplicable = [];
        $active = [];

        foreach ($flat as $key => $value) {
            // Inactive: nothing was filtered on, so nothing has to be applied.
            if ($value === null || $value === [] || (is_scalar($value) && trim((string) $value) === '')) {
                continue;
            }

            if (! is_scalar($value) || ! in_array($key, $allowed, true)) {
                $unapplicable[] = $key;

                continue;
            }

            $active[$key] = trim((string) $value);
        }

        if ($unapplicable !== []) {
            throw ValidationException::withMessages([
                'filter_snapshot' => __('sk-bulk.unknown_filters', [
                    'keys' => implode(', ', $unapplicable),
                ]),
            ]);
        }

        // Rebuild in allow-list order so the result is deterministic regardless
        // of the key order the client happened to send.
        $filters = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $active)) {
                $filters[$key] = $active[$key];
            }
        }

        return $filters;
    }

    /**
     * Collapse both accepted snapshot shapes into one flat filter map.
     * Every non-`filter` key is dropped here (see contract #2).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function flatten(array $snapshot): array
    {
        $flat = [];

        // Nested shape: { filter: { status: 'active' } }
        if (isset($snapshot['filter']) && is_array($snapshot['filter'])) {
            foreach ($snapshot['filter'] as $key => $value) {
                $flat[(string) $key] = $value;
            }
        }

        // Bracket-style keys: 'filter[status]' => 'active'
        foreach ($snapshot as $key => $value) {
            if (is_string($key) && preg_match('/^filter\[(.+)\]$/', $key, $m) === 1) {
                $flat[$m[1]] = $value;
            }
        }

        return $flat;
    }
}
