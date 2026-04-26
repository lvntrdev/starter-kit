<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    // CRUD success
    'created' => ':entity created successfully.',
    'updated' => ':entity updated successfully.',
    'deleted' => ':entity deleted successfully.',
    'restored' => ':entity restored successfully.',
    'saved' => 'Changes saved successfully.',
    'permissions_synced' => 'Permissions synced successfully.',

    // Generic outcomes
    'operation_success' => 'Operation completed successfully.',
    'operation_failed' => 'Operation failed. Please try again.',
    'no_changes' => 'No changes were made.',

    // Errors / access
    'not_found' => 'The requested resource could not be found.',
    'unauthorized' => 'You are not authorized to perform this action.',
    'forbidden' => 'You do not have permission to access this resource.',
    'server_error' => 'An unexpected error occurred. Please try again later.',
    'validation_failed' => 'The provided data is invalid.',

    // Confirmation prompts
    'confirm_action' => 'Are you sure you want to perform this action?',
    'confirm_delete' => 'Are you sure you want to delete this item? This action cannot be undone.',

    // Mail
    'email_sent' => 'Email sent successfully.',
    'email_failed' => 'Failed to send email. Please check your mail configuration.',

    // Client-side HTTP errors (used by useApi composable)
    'error_summary' => 'Error',
    'invalid_response' => 'The server returned an invalid response.',
    'request_failed' => 'Request failed (:status).',
    'network_error' => 'Network error. Please try again.',

];
