<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for bulk action POST requests.
 *
 * Shape:
 * {
 *   "action": "delete",
 *   "ids": [1, 2, 3],
 *   "select_all_filtered": false,       // optional — true means "all matching filter"
 *   "filter_snapshot": { ... }           // optional — active filter state for select_all_filtered
 * }
 *
 * 'ids' is required unless select_all_filtered is true. In cross-page mode
 * the controller ignores ids and re-resolves the set from filter_snapshot
 * through the domain's BulkSelectionQuery (bounded by its MAX_ITEMS cap), so
 * an empty or absent ids is valid there — the shipped useDatatableSelection()
 * composable posts `ids: []` in "all" mode once nothing on the current page
 * is selected. Any ids that ARE sent are still shape-checked in both modes
 * (array, max:500, opaque strings).
 *
 * Note: table-level existence checks (ids.* exists in DB) are intentionally
 * omitted here — the controller resolves the model collection directly
 * (with permission-scoped base query) and silently skips missing ids.
 * This avoids leaking model existence to actors who cannot see the items.
 */
class BulkActionRequest extends FormRequest
{
    /**
     * Authorization: basic authentication only (domain controllers add model-level checks).
     *
     * Gate-level permission ('users.delete', 'roles.delete', etc.) is checked
     * inside the domain-specific BulkAction::authorize() per-item method.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->ids)) {
            $this->merge([
                'ids' => array_map(
                    fn ($id) => is_scalar($id) ? (string) $id : $id,
                    $this->ids,
                ),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:64'],
            // Page mode needs at least one id (`required` already rejects an
            // empty array); cross-page mode resolves the set from filter_snapshot.
            'ids' => [Rule::requiredIf(fn (): bool => ! $this->boolean('select_all_filtered')), 'array', 'max:500'],
            // ID type-agnostic: integer auto-increment, UUID (36 char), ULID (26 char) — all accepted.
            'ids.*' => ['required', 'string', 'min:1', 'max:64'],
            'select_all_filtered' => ['sometimes', 'boolean'],
            'filter_snapshot' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sk-bulk.ids_required'),
            'ids.max' => __('sk-bulk.ids_max'),
            'action.required' => __('sk-bulk.action_required'),
        ];
    }
}
