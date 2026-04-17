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

    'file_manager' => [
        'title' => 'File Manager Settings',
        'subtitle' => 'Configure upload size and accepted file types.',
    ],

    'turnstile' => [
        'title' => 'Cloudflare Turnstile',
        'subtitle' => 'Protect login, register and forgot password forms with CAPTCHA.',
        'enabled_hint' => 'Enable Turnstile challenge',
        'site_key_label' => 'Site Key',
        'secret_key_label' => 'Secret Key',
    ],
];
