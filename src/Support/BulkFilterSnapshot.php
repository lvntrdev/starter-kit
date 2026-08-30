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
 *   3. ONLY NULL AND [] ARE INACTIVE. Those are exactly the two shapes Spatie's
 *      AllowedFilter skips, so the table applies nothing for them and neither
 *      does this. Anything else present on an allow-listed key — an empty or
 *      whitespace-only string included — IS a value: the table's exact filter
 *      renders `WHERE status = ''` for it (an empty set), so treating it as
 *      "no filter" here would resolve a WIDER set. The value is passed through
 *      verbatim (no trim — the table trims nothing either) and each query
 *      applies it with the table's own predicate, so `search` and the date
 *      bounds still no-op on blank input exactly as the table does.
 *
 *   4. SAME VALUE SHAPE AS THE TABLE. Spatie's QueryBuilderRequest::
 *      getFilterValue() turns the literal strings `true` / `false` into
 *      booleans BEFORE the table's filter sees them — an exact filter binds
 *      `WHERE status = 1`, and the search callback receives bool true (which
 *      it applies as "1"). The snapshot carries the raw strings, so they are
 *      coerced here exactly the same way; passing them through as text would
 *      make the bulk side search the word "true" while the table searched
 *      "1", resolving a DIFFERENT (possibly wider) set. Every other scalar
 *      stays a verbatim string.
 */
final class BulkFilterSnapshot
{
    /**
     * Flatten + validate the snapshot into an `allow-listed key => value` map.
     *
     * Accepts both shapes the frontend can produce: bracket-style keys coming
     * from URLSearchParams (`filter[status]`) and an already-nested
     * `['filter' => ['status' => ...]]` array.
     *
     * @param  array<string, mixed>  $snapshot  Raw client snapshot.
     * @param  string[]  $allowed  Filter keys the caller's query can actually apply.
     * @return array<string, string|bool> Keyed in allow-list order; values verbatim (never
     *                                    trimmed), except a literal `true` / `false` string,
     *                                    which becomes the boolean Spatie hands the table.
     *
     * @throws ValidationException When the snapshot carries an active filter the query cannot apply.
     */
    public static function normalize(array $snapshot, array $allowed): array
    {
        $flat = self::flatten($snapshot);

        $unapplicable = [];
        $active = [];

        foreach ($flat as $key => $value) {
            // Inactive: the two shapes Spatie itself skips (contract #3). A blank
            // string is NOT one of them — the table applies it as a value.
            if ($value === null || $value === []) {
                continue;
            }

            if (! is_scalar($value) || ! in_array($key, $allowed, true)) {
                $unapplicable[] = $key;

                continue;
            }

            $active[$key] = self::coerce($value);
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
     * Mirror Spatie's QueryBuilderRequest::getFilterValue() scalar coercion
     * (contract #4): the literal strings 'true' / 'false' become booleans, a
     * JSON boolean (nested snapshot shape) is kept as-is, everything else is
     * the verbatim string. Case-sensitive, exactly like Spatie's comparison.
     */
    private static function coerce(bool|int|float|string $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        return (string) $value;
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
