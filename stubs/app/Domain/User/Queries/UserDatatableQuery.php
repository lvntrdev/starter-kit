<?php

namespace App\Domain\User\Queries;

use App\Enums\RoleEnum;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\DatatableQueryBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
        $query = User::query();

        if (! $currentUser->hasRole(RoleEnum::SystemAdmin)) {
            $userMinSortOrder = (int) $currentUser->roles->min('sort_order');

            $query->whereDoesntHave('roles', function (Builder $q) use ($userMinSortOrder) {
                $q->where('sort_order', '<', $userMinSortOrder);
            });
        }

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
            ])
            ->filterable([
                'status',
                AllowedFilter::callback('role', function (Builder $q, $value) {
                    $q->whereHas('roles', fn (Builder $r) => $r->where('name', $value));
                }),
            ])
            ->with(['roles', 'media'])
            ->defaultSort('-created_at')
            ->resource(UserResource::class)
            ->response();
    }
}
