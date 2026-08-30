<?php

namespace Lvntr\StarterKit\Http\Responses;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
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
 *       ->columns([
 *           ['key' => 'name', 'locked' => true],
 *           'email',
 *           ['key' => 'created_at', 'visible' => false],
 *       ])
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

    /** @var array<int, array{key: string, label?: string, sortable?: bool, visible?: bool, locked?: bool}> */
    private array $columns = [];

    /** @var string[] Row keys always kept when the payload is shaped per column. */
    private array $alwaysInclude = ['id'];

    /** @var string[] */
    private array $withRelations = [];

    private int $defaultPerPage;

    /** @var class-string<JsonResource>|null */
    private ?string $resourceClass = null;

    private function __construct(string|Builder $subject)
    {
        $this->subject = $subject;
        $this->defaultPerPage = (int) (config('starter-kit.datatable.default_per_page') ?? 10);
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
     * Create inclusive local-calendar date filters for a UTC datetime column.
     *
     * @return list<AllowedFilter>
     */
    public static function dateRangeFilters(string $column): array
    {
        return [
            AllowedFilter::callback("{$column}_from", function (Builder $query, mixed $value) use ($column): void {
                self::applyCalendarDateRange($query, $column, from: $value);
            }),
            AllowedFilter::callback("{$column}_to", function (Builder $query, mixed $value) use ($column): void {
                self::applyCalendarDateRange($query, $column, to: $value);
            }),
        ];
    }

    /**
     * Apply the inclusive local-calendar date predicate for a UTC datetime column.
     *
     * SINGLE SOURCE OF TRUTH for the date-range semantics: the datatable's
     * dateRangeFilters() closures AND the cross-page bulk selection queries both
     * go through here, so the visible set and the bulk set can never diverge on
     * timezone/DST boundaries. Bounds are resolved in the user's display
     * timezone: `from` is >= local start-of-day, `to` is < local start of the
     * NEXT day (so the `to` day itself is included).
     *
     * A value that is not a valid `Y-m-d` calendar date is ignored — exactly as
     * the datatable ignores it — rather than raising an error.
     *
     * @param  Builder<Model>  $query
     */
    public static function applyCalendarDateRange(Builder $query, string $column, mixed $from = null, mixed $to = null): void
    {
        $fromDate = self::parseCalendarDate($from);

        if ($fromDate !== null) {
            $query->where($column, '>=', $fromDate->startOfDay()->utc());
        }

        $toDate = self::parseCalendarDate($to);

        if ($toDate !== null) {
            $query->where($column, '<', $toDate->startOfDay()->addDay()->utc());
        }
    }

    /**
     * Declare the column list the table offers (column visibility menu).
     *
     * Each entry is a key string or an array: ['key' => 'email', 'label' => 'sk-common.email',
     * 'sortable' => true, 'visible' => false, 'locked' => true]. The list is sent to the
     * frontend as `columns` meta — including columns hidden by default — and enables payload
     * shaping: when the request carries ?columns=key1,key2 only those columns' data (plus
     * alwaysInclude() keys) is returned per row.
     *
     * @param  array<int, string|array{key: string, label?: string, sortable?: bool, visible?: bool, locked?: bool}>  $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = array_map(
            fn (string|array $column) => is_string($column) ? ['key' => $column] : $column,
            array_values($columns),
        );

        return $this;
    }

    /**
     * Row keys always kept when shaping the payload to the requested columns —
     * fields that row actions/handlers need regardless of visibility. Default: ['id'].
     *
     * @param  string[]  $keys
     */
    public function alwaysInclude(array $keys): static
    {
        $this->alwaysInclude = array_values(array_unique(['id', ...$keys]));

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

        $maxPerPage = (int) (config('starter-kit.datatable.max_per_page') ?? 100);
        $perPage = min($maxPerPage, max(1, (int) request()->input('per_page', $this->defaultPerPage)));
        $paginator = $query->paginate($perPage)->withQueryString();

        $items = $this->resourceClass
            ? $this->resourceClass::collection($paginator->getCollection())->resolve()
            : $paginator->items();

        $payload = [
            'data' => $this->columns !== [] ? $this->shapeItems($items) : $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        if ($this->columns !== []) {
            $payload['columns'] = $this->columnsMeta();
        }

        return ApiResponse::success($payload);
    }

    /**
     * Column meta sent to the frontend column menu (null entries dropped per column).
     *
     * @return array<int, array<string, string|bool>>
     */
    private function columnsMeta(): array
    {
        return array_map(
            fn (array $column) => array_filter([
                'key' => $column['key'],
                'label' => $column['label'] ?? null,
                'sortable' => $column['sortable'] ?? null,
                'visible' => $column['visible'] ?? null,
                'locked' => $column['locked'] ?? null,
            ], fn ($value) => $value !== null),
            $this->columns,
        );
    }

    /**
     * Shape each row down to the requested ?columns selection (+ alwaysInclude keys).
     * Fail-closed: the full row is only returned when the request carries no `columns`
     * parameter at all. Once the parameter is present — empty, unknown-only, or
     * partially valid — every row is reduced to alwaysInclude() keys plus whichever
     * requested keys are valid (possibly none), never the full row.
     * Dot keys (e.g. role.name) keep their top-level segment.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function shapeItems(array $items): array
    {
        if (! request()->has('columns')) {
            return $items;
        }

        $requested = array_filter(explode(',', (string) request()->input('columns', '')));

        $allowed = array_column($this->columns, 'key');
        $selected = array_values(array_intersect($requested, $allowed));

        $keep = array_flip(array_unique([
            ...$this->alwaysInclude,
            ...array_map(fn (string $key) => explode('.', $key)[0], $selected),
        ]));

        return array_map(function ($item) use ($keep) {
            $row = $item instanceof Arrayable ? $item->toArray() : (array) $item;

            return array_intersect_key($row, $keep);
        }, $items);
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

    private static function parseCalendarDate(mixed $value): ?Carbon
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::parse($value, resolve_display_timezone());
    }
}
