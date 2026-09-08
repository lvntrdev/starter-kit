<?php

namespace Lvntr\StarterKit\Domain\User\Queries;

use App\Enums\RoleEnum;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\DatatableQueryBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * Query: Build the user datatable response with role hierarchy filtering.
 *
 * Non-system_admin users can only see users whose highest role is at
 * the same level or below their own in the hierarchy (sort_order >= theirs).
 */
class UserDatatableQuery
{
    public function response(User $currentUser): ApiResponse
    {
        $query = self::applyVisibilityScope(User::query(), $currentUser);

        return DatatableQueryBuilder::for($query)
            ->searchable(['id', 'first_name', 'last_name', 'email'])
            ->sortable([
                'id',
                'first_name',
                'last_name',
                AllowedSort::field('full_name', 'first_name'),
                'email',
                'status',
                'created_at',
                'updated_at',
            ])
            ->columns([
                ['key' => 'full_name', 'locked' => true],
                'email',
                ['key' => 'role', 'sortable' => false],
                'status',
                'created_at',
                ['key' => 'updated_at', 'label' => 'sk-common.updated_at', 'visible' => false],
            ])
            ->alwaysInclude(['full_name', 'role_color'])
            ->filterable([
                'status',
                AllowedFilter::callback('role', function (Builder $q, $value) {
                    $q->whereHas('roles', fn (Builder $r) => $r->where('name', $value));
                }),
                ...DatatableQueryBuilder::dateRangeFilters('created_at'),
            ])
            ->with(['roles', 'media'])
            ->defaultSort('-created_at')
            ->resource(UserResource::class)
            ->response();
    }

    /**
     * Apply the role-hierarchy visibility scope to a User query.
     *
     * This is the SINGLE SOURCE OF TRUTH for "which users may the actor see".
     * It is consumed by the datatable listing (response()) AND by the
     * cross-page "select all filtered" bulk re-query
     * (UserBulkSelectionQuery) so the two can never diverge — a divergence
     * would let "select all filtered" reach users the actor cannot see in the
     * table, bypassing the hierarchy filter (a privilege-escalation hole).
     *
     * Rule: a non-system_admin actor may only see users whose highest role is
     * at the same level or below their own (sort_order >= the actor's minimum).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function applyVisibilityScope(Builder $query, User $currentUser): Builder
    {
        return self::scopeByHierarchy(
            $query,
            $currentUser->hasRole(RoleEnum::SystemAdmin),
            self::nullableInt($currentUser->roles->min('sort_order')),
        );
    }

    /**
     * Builder-level role-hierarchy scope, decoupled from the User model so it
     * can be unit-tested directly. applyVisibilityScope() is the User-typed
     * entry point; this is the actual WHERE logic.
     *
     * @param  Builder<User>  $query
     * @param  bool  $isSystemAdmin  Actor bypasses the filter entirely.
     * @param  int|null  $minSortOrder  Actor's highest rank (lowest sort_order);
     *                                  null means the actor has no role at all.
     * @return Builder<User>
     */
    public static function scopeByHierarchy(Builder $query, bool $isSystemAdmin, ?int $minSortOrder): Builder
    {
        if ($isSystemAdmin) {
            return $query;
        }

        if ($minSortOrder === null) {
            // Actor has no role at all (e.g. direct-permission user) — the
            // lowest possible rank, so they may only see other role-less
            // users. Casting null → 0 would disable the hierarchy filter
            // entirely (no role has sort_order < 0).
            $query->whereDoesntHave('roles');
        } else {
            $query->whereDoesntHave('roles', function (Builder $q) use ($minSortOrder) {
                $q->where('sort_order', '<', $minSortOrder);
            });
        }

        return $query;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
