<?php

return [
    // FormRequest validation messages
    'ids_required' => 'At least one item must be selected.',
    'ids_min' => 'At least one item must be selected.',
    'ids_max' => 'A maximum of 500 items can be processed at once.',
    'action_required' => 'A bulk action must be specified.',

    // Dispatcher messages
    'unsupported_action' => 'Unsupported bulk action: :action.',
    'no_authorized_items' => 'You are not authorized to perform this action on any of the selected items.',

    // Result message
    'result' => ':processed item(s) processed, :skipped skipped, :failed failed.',

    // Cross-page "select all filtered" cap warning
    'cap_reached' => 'Selection reached the upper limit; only the first :max items were processed.',

    // Cross-page "select all filtered" fail-closed snapshot guard
    'unknown_filters' => 'This bulk action cannot be applied: the active filter(s) :keys are not supported for select-all. Clear them or select the items individually.',
];
