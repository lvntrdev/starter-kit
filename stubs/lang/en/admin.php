<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    */

    'menu' => [
        'dashboard' => 'Dashboard',
        'user_management' => 'User Management',
        'users' => 'Users',
        'roles_permissions' => 'Roles & Permissions',
        'system' => 'System',
        'activity_logs' => 'Activity Logs',
        'settings' => 'Settings',
        'profile' => 'Profile',
        'logout' => 'Logout',
        'developer' => 'Developer',
        'api_routes' => 'API Routes',
        'api_docs' => 'API Docs',
        'pulse' => 'Pulse',
        'laravel_docs' => 'Laravel Docs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */

    'layout' => [
        'open_menu' => 'Open menu',
        'expand_menu' => 'Expand menu',
        'collapse_menu' => 'Collapse menu',
        'light_mode' => 'Light mode',
        'dark_mode' => 'Dark mode',
        'notifications' => 'Notifications',
        'success' => 'Success',
        'error' => 'Error',
        'warning' => 'Warning',
        'info' => 'Info',
    ],

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    'users' => [
        'title' => 'Users',
        'user' => 'User',
        'subtitle' => 'User Management',
        'create' => 'Create User',
        'edit' => 'Edit User',
        'delete' => 'Delete User',
        'delete_confirm' => 'Are you sure you want to delete the user ":name"?',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    'roles' => [
        'title' => 'Roles',
        'role' => 'Role',
        'subtitle' => 'Roles & Permissions',
        'create' => 'Create Role',
        'edit' => 'Edit Role',
        'role_name_placeholder' => 'e.g. editor',
        'group' => 'Group',
        'group_placeholder' => 'Select a group...',
        'permissions' => 'Permissions',
        'users' => 'Users',
        'resource' => 'Resource',
        'no_permissions_available' => 'No permissions have been defined yet.',
        'delete_confirm' => 'Are you sure you want to delete the role ":name"?',
        'sync_permissions' => 'Sync Permissions',

        'resources' => [
            'users' => 'Users',
            'roles' => 'Roles',
            'activity-logs' => 'Activity Logs',
            'settings' => 'Settings',
            'api-routes' => 'API Routes',
            'pulse' => 'Pulse',
            'api-docs' => 'API Docs',
        ],

        'abilities' => [
            'create' => 'Create',
            'read' => 'Read',
            'update' => 'Update',
            'delete' => 'Delete',
            'import' => 'Import',
            'export' => 'Export',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity Logs
    |--------------------------------------------------------------------------
    */

    'activity_logs' => [
        'title' => 'Activity Logs',
        'subtitle' => 'View model changes and user actions.',
        'detail_title' => 'Activity Log Detail',
        'event' => 'Event',
        'description' => 'Description',
        'model' => 'Model',
        'model_id' => 'Model ID',
        'causer' => 'Causer',
        'date' => 'Date',
        'log_name' => 'Log Name',
        'detail' => 'Detail',
        'changes' => 'Changes',
        'field' => 'Field',
        'old' => 'Old',
        'new' => 'New',
        'properties' => 'Properties',
        'batch' => 'Batch: :uuid',
        'event_created' => 'Created',
        'event_updated' => 'Updated',
        'event_deleted' => 'Deleted',
        'filter_user' => 'User',
        'filter_role' => 'Role',
        'filter_permission' => 'Permission',
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    'settings' => [
        'title' => 'Settings',
        'subtitle' => 'Manage application configuration.',

        'tabs' => [
            'general' => 'General',
            'auth' => 'Authentication',
            'mail' => 'Mail',
            'storage' => 'Storage',
        ],

        'general' => [
            'title' => 'General Settings',
            'subtitle' => 'Configure basic application settings.',
            'languages_hint' => 'Select the languages your application supports.',
            'logo' => 'Application Logo',
            'logo_hint' => 'Upload a logo to display in the sidebar and login page.',
            'logo_upload' => 'Upload Logo',
            'logo_remove' => 'Remove',
            'logo_remove_confirm' => 'Are you sure you want to remove the application logo?',
        ],

        'auth' => [
            'title' => 'Authentication Settings',
            'subtitle' => 'Enable or disable authentication features.',
            'registration_hint' => 'Allow new users to register an account.',
            'email_verification_hint' => 'Require users to verify their email address after registration.',
            'two_factor_hint' => 'Allow users to enable two-factor authentication for added security.',
            'password_reset_hint' => 'Allow users to reset their password via email link.',
        ],

        'mail' => [
            'title' => 'Mail Settings',
            'subtitle' => 'Configure outgoing email settings.',
            'encryption_none' => 'None',
            'test_title' => 'Test Email',
            'test_subtitle' => 'Send a test email to verify your mail configuration.',
            'test_send' => 'Send Test',
        ],

        'storage' => [
            'title' => 'Media Storage',
            'subtitle' => 'Select where uploaded media files should be stored.',
            'local' => 'Local',
            'spaces' => 'DigitalOcean Spaces',
            's3' => 'Amazon S3',
            'spaces_title' => 'DigitalOcean Spaces',
            'spaces_subtitle' => 'Configure DigitalOcean Spaces credentials and settings.',
            's3_title' => 'Amazon S3',
            's3_subtitle' => 'Configure AWS S3 credentials and settings.',

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    'profile' => [
        'title' => 'Profile',
        'subtitle' => 'Manage your account settings.',
        'info_title' => 'Profile Information',
        'info_subtitle' => 'Update your name and email address.',

        'password_title' => 'Update Password',
        'password_subtitle' => 'Ensure your account uses a strong password.',
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'update_password' => 'Update Password',
        'password_updated' => 'Password updated.',

        'two_factor_title' => 'Two-Factor Authentication',
        'two_factor_subtitle' => 'Add additional security to your account using two-factor authentication.',
        'two_factor_enabled' => 'Two-factor authentication is enabled.',
        'two_factor_disabled' => 'Two-factor authentication is not enabled. Enable it to add an extra layer of security.',
        'two_factor_finish' => 'Finish setting up two-factor authentication.',
        'two_factor_scan' => 'Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.):',
        'two_factor_manual' => 'Or enter this setup key manually:',
        'two_factor_code_label' => 'Enter the 6-digit code from your authenticator app',
        'two_factor_verify' => 'Verify & Activate',
        'two_factor_cancel_setup' => 'Cancel Setup',
        'two_factor_continue' => 'Continue Setup',
        'two_factor_expired' => 'Your setup session has expired. Please confirm your password to continue or cancel the setup.',
        'two_factor_recovery_info' => 'Store these recovery codes in a safe place. They can be used to access your account if you lose your authenticator device.',
        'two_factor_regenerate' => 'Regenerate Recovery Codes',
        'two_factor_enable' => 'Enable',
        'two_factor_show_codes' => 'Show Recovery Codes',
        'two_factor_disable' => 'Disable',
        'confirm_password_title' => 'Confirm Password',
        'confirm_password_message' => 'Please confirm your password before continuing.',

        'sessions_title' => 'Browser Sessions',
        'sessions_subtitle' => 'Manage and log out your active sessions on other browsers and devices.',
        'sessions_description' => 'If necessary, you may log out of all of your other browser sessions across all of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive. If you feel your account has been compromised, you should also update your password.',
        'sessions_this_device' => 'This device',
        'sessions_last_active' => 'Last active :time',
        'sessions_logout' => 'Log Out Other Browser Sessions',
        'sessions_done' => 'Done.',
        'sessions_logout_confirm' => 'Please enter your password to confirm you would like to log out of your other browser sessions across all of your devices.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatar
    |--------------------------------------------------------------------------
    */

    'avatar' => [
        'title' => 'Profile Photo',
        'subtitle' => 'Upload a profile photo to personalize your account.',
        'change' => 'Change',
        'remove' => 'Remove',
        'hint' => 'JPG, PNG or GIF. Max 2MB.',
        'remove_confirm' => 'Are you sure you want to remove this image?',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    */

    'api_routes' => [
        'title' => 'API & Service Routes',
        'subtitle' => 'Overview of all registered API and internal service endpoints.',
        'api_endpoints' => 'API Endpoints',
        'api_endpoints_subtitle' => 'Public and authenticated REST API routes.',
        'service_endpoints' => 'Service Endpoints',
        'service_endpoints_subtitle' => 'Internal service routes consumed by frontend components.',
        'method' => 'Method',
        'uri' => 'URI',
        'name' => 'Name',
        'action' => 'Action',
        'middleware' => 'Middleware',
        'no_routes' => 'No routes found.',
        'open_api_docs' => 'Open API Docs',
        'open_pulse' => 'Open Pulse',
        'regenerate_docs' => 'Regenerate Docs',
        'regenerate_docs_success' => 'API documentation regenerated successfully.',
    ],
];
