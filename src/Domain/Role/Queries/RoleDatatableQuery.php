<?php

namespace Lvntr\StarterKit\Domain\Role\Queries;

use App\Http\Responses\DatatableQueryBuilder;
use App\Models\Role;
use Lvntr\StarterKit\Http\Responses\ApiResponse;

/**
 * Query: Build the role datatable response with permission and user counts.
 */
class RoleDatatableQuery
{
    public function response(): ApiResponse
    {
        return DatatableQueryBuilder::for(Role::query()->withCount(['permissions', 'users']))
            ->searchable(['id', 'name'])
            ->sortable(['id', 'name', 'permissions_count', 'users_count', 'sort_order', 'created_at'])
            ->defaultSort('sort_order')
            ->response();
    }
}
