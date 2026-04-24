<?php

return [
    'title' => 'Settings',
    'subtitle' => 'Manage application configuration.',

    'tabs' => [
        'general' => 'General',
        'auth' => 'Authentication',
        'mail' => 'Mail',
        'storage' => 'Storage',
        'file_manager' => 'File Manager',
        'turnstile' => 'Security',
        'api_clients' => 'API Clients',
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
        'welcome_message_placeholder' => 'Write a short welcome message…',
        'welcome_message_hint' => 'Shown on the admin dashboard. Supports basic formatting and images.',
    ],

    'auth' => [
        'title' => 'Authentication Settings',
        'subtitle' => 'Enable or disable authentication features.',
        'registration_hint' => 'Allow new users to register an account.',
        'email_verification_hint' => 'Require users to verify their email address after registration.',
        'two_factor_hint' => 'Allow users to enable two-factor authentication for added security.',
        'two_factor_disable_title' => 'Disable two-factor authentication?',
        'two_factor_disable_warning' => 'Turning this off removes the extra login check for everyone. Existing 2FA secrets will be cleared and affected users will have to re-enroll if you enable it again later.',
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

    'file_manager' => [
        'title' => 'File Manager Settings',
        'subtitle' => 'Configure upload size and accepted file types.',
        'mime_categories' => [
            'images' => 'Images',
            'documents' => 'Documents',
            'archive' => 'Archive',
        ],
        'media_cards' => [
            'video' => [
                'label' => 'Video uploads',
                'description' => 'Allow MP4, WebM, MOV, MKV, AVI and OGG videos.',
            ],
            'audio' => [
                'label' => 'Audio uploads',
                'description' => 'Allow MP3, WAV, OGG and WebM audio files.',
            ],
        ],
    ],

    'turnstile' => [
        'title' => 'Cloudflare Turnstile',
        'subtitle' => 'Protect login, register and forgot password forms with CAPTCHA.',
        'enabled_hint' => 'Enable Turnstile challenge',
        'site_key_label' => 'Site Key',
        'secret_key_label' => 'Secret Key',
    ],

    'postman' => [
        'title' => 'Postman Integration',
        'subtitle' => 'Push the generated OpenAPI spec to Postman from the API Routes page.',
        'api_key_label' => 'API Key',
        'api_key_hint' => 'Personal API key from postman.co → Settings → API Keys (starts with PMAK-).',
        'workspace_id_label' => 'Workspace ID',
        'workspace_id_hint' => 'UUID portion of the workspace URL.',
        'collection_id_label' => 'Current Postman Collection UID',
        'collection_id_hint' => 'Automatically updated after each Postman sync. No need to edit manually.',
    ],

    'apidog' => [
        'title' => 'Apidog Integration',
        'subtitle' => 'Push the generated OpenAPI spec to an existing Apidog project (endpoints are overwritten in place).',
        'access_token_label' => 'Access Token',
        'access_token_hint' => 'Personal access token from apidog.com → Account Settings → API Access Token.',
        'project_id_label' => 'Project ID',
        'project_id_hint' => 'Numeric project ID from the Apidog project URL (…/project/<id>).',
    ],
];
