<?php

namespace App\Http\Responses;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Fluent wrapper around Spatie QueryBuilder for DataTable endpoints.
 *
 * Eliminates repetitive filter/sort/search setup per resource.
 * Produces the exact JSON shape that the <DataTable> Vue component expects.
 *
 * Usage:
 *   return DatatableQueryBuilder::for(User::class)
 *       ->searchable(['name', 'email'])
 *       ->sortable(['id', 'name', 'email', 'status', 'created_at'])
 *       ->filterable(['status'])
 *       ->defaultSort('-created_at')
 *       ->response();
 */
class DatatableQueryBuilder
{
    /** @var class-string<Model>|Builder<Model> */
    private string|Builder $subject;

    /** @var string[] */
    private array $searchFields = [];

    /** @var array<string|AllowedSort> */
    private array $sortFields = [];

    /** @var array<string|AllowedFilter> */
    private array $filterFields = [];

    private string $defaultSortField = '-created_at';

    /** @var string[] */
    private array $withRelations = [];

    private int $defaultPerPage;

    /** @var class-string<JsonResource>|null */
    private ?string $resourceClass = null;

    private function __construct(string|Builder $subject)
    {
        $this->subject = $subject;
        $this->defaultPerPage = (int) config('starter-kit.datatable.default_per_page', 10);
    }

    /**
     * @param  class-string<Model>|Builder<Model>  $subject
     */
    public static function for(string|Builder $subject): self
    {
        return new self($subject);
    }

    /**
     * Columns that are searchable via filter[search].
     *
     * Semantics: the search value is split by whitespace into words; each word
     * must match at least one of the given columns (LIKE '%word%', OR across
     * columns) AND all words must match (AND across words). So a query like
     * `filter[search]=john doe` against `['name', 'email']` matches rows where
     * every word appears in at least one of name/email. Wildcards `%` and `_`
     * in the search value are escaped.
     *
     * @param  string[]  $fields
     */
    public function searchable(array $fields): static
    {
        $this->searchFields = $fields;

        return $this;
    }

    /**
     * Columns allowed for sorting via ?sort=name or ?sort=-name.
     *
     * @param  array<string|AllowedSort>  $fields
     */
    public function sortable(array $fields): static
    {
        $this->sortFields = $fields;

        return $this;
    }

    /**
     * Columns allowed for filtering via ?filter[status]=active.
     *
     * @param  array<string|AllowedFilter>  $fields
     */
    public function filterable(array $fields): static
    {
        $this->filterFields = $fields;

        return $this;
    }

    /**
     * Default sort when no ?sort param is present. Prefix with - for desc.
     */
    public function defaultSort(string $field): static
    {
        $this->defaultSortField = $field;

        return $this;
    }

    /**
     * Eager-load relations.
     *
     * @param  string[]  $relations
     */
    public function with(array $relations): static
    {
        $this->withRelations = $relations;

        return $this;
    }

    /**
     * Wrap each item with the given JsonResource.
     *
     * @param  class-string<JsonResource>  $resourceClass
     */
    public function resource(string $resourceClass): static
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    /**
     * Default per-page count.
     */
    public function perPage(int $perPage): static
    {
        $this->defaultPerPage = $perPage;

        return $this;
    }

    /**
     * Build the Spatie QueryBuilder and return an ApiResponse
     * in the shape the DataTable component expects.
     */
    public function response(): ApiResponse
    {
        $query = $this->buildQuery();

        $perPage = max(1, (int) request()->input('per_page', $this->defaultPerPage));
        $paginator = $query->paginate($perPage)->withQueryString();

        $items = $this->resourceClass
            ? $this->resourceClass::collection($paginator->getCollection())->resolve()
            : $paginator->items();

        return ApiResponse::success([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    /**
     * Build the underlying Spatie QueryBuilder with all allowed filters/sorts.
     */
    private function buildQuery(): QueryBuilder
    {
        $allowedFilters = $this->buildAllowedFilters();
        $allowedSorts = array_map(
            fn (string|AllowedSort $field) => $field instanceof AllowedSort ? $field : AllowedSort::field($field),
            $this->sortFields,
        );

        $query = QueryBuilder::for($this->subject)
            ->allowedFilters($allowedFilters)
            ->allowedSorts($allowedSorts)
            ->defaultSort($this->defaultSortField);

        if ($this->withRelations) {
            $query->with($this->withRelations);
        }

        return $query;
    }

    /**
     * Merge search filter + exact filters into a single allowed list.
     *
     * @return array<AllowedFilter>
     */
    private function buildAllowedFilters(): array
    {
        $filters = [];

        if ($this->searchFields) {
            $filters[] = AllowedFilter::callback('search', function (Builder $query, $value) {
                $words = array_filter(explode(' ', trim($value)));

                $query->where(function (Builder $q) use ($words) {
                    foreach ($words as $word) {
                        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $word);
                        $q->where(function (Builder $inner) use ($escaped) {
                            foreach ($this->searchFields as $field) {
                                $inner->orWhere($field, 'like', '%'.$escaped.'%');
                            }
                        });
                    }
                });
            });
        }

        foreach ($this->filterFields as $field) {
            if ($field instanceof AllowedFilter) {
                $filters[] = $field;
            } else {
                $filters[] = AllowedFilter::exact($field);
            }
        }

        return $filters;
    }
}
