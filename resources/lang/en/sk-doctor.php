<?php

// Message keys used by src/Console/Doctor/Checks/*. Each top-level key is the
// check class name in snake_case without the "Check" suffix. The EN value
// below must stay character-identical to the string the class currently
// produces (placeholders aside) — tests assert on English substrings and the
// test locale is "en".

return [

    // ActivityLogSecretsCheck
    'activity_log_secrets' => [
        'name' => 'Activity Log Secrets',
        'probe_failed' => 'Could not inspect the activity log for stored credentials: :error',
        'probe_failed_hint' => 'Check the database connection, then run `php artisan sk:redact-activity-secrets --dry-run --all` by hand.',
        'no_table' => 'No activity log table on this connection — there is nothing that could hold a credential.',
        'no_json_column' => 'Table [:table] has no JSON payload column — there is nowhere for a credential to be stored.',
        'dirty_exhaustive' => ':count row(s) in [:table] still contain credentials (password hashes, tokens or secrets) readable from the activity-log screen.',
        'dirty_exhaustive_hint' => 'Back up the database, then run `php artisan migrate` (or `php artisan sk:redact-activity-secrets`). Removal is irreversible.',
        'dirty_bounded' => 'At least :count row(s) in [:table] still contain credentials (password hashes, tokens or secrets) readable from the activity-log screen. The probe stopped after :scanned row(s), so that is a floor, not the total.',
        'dirty_bounded_hint' => 'Back up the database, then run `php artisan migrate` (or `php artisan sk:redact-activity-secrets`). Removal is irreversible.',
        'invalid_json' => ':count JSON payload(s) in [:table] could not be decoded, so they could not be checked for credentials.',
        'invalid_json_hint' => 'Inspect those rows by hand — `php artisan sk:redact-activity-secrets --dry-run --all` reports the count over the whole table.',
        'clean_exhaustive' => 'No credential-bearing rows found in [:table] (all :scanned row(s) inspected).',
        'clean_bounded' => 'No credential-bearing rows in the first :scanned row(s) of [:table] — a bounded probe, not a full audit. Run `php artisan sk:redact-activity-secrets --dry-run --all` for the exhaustive count.',
    ],

    // ConfigCacheCheck
    'config_cache' => [
        'name' => 'Config Cache',
        'production_missing' => 'Config cache not found in production environment.',
        'production_missing_hint' => 'Run php artisan config:cache (recommended for performance).',
        'production_ready' => 'Config cache exists and is ready for production.',
        'stale_local' => 'Config cache exists but environment is ":env" — config changes may not be reflected.',
        'stale_local_hint' => 'Clear the cache with php artisan config:clear.',
        'not_required' => 'Environment ":env" — config cache is not required.',
    ],

    // DataEncryptionKeyCheck
    'data_encryption_key' => [
        'name' => 'Data Encryption Key',
        'chain_unresolved' => 'The data encryption key chain could not be resolved: :error',
        'chain_unresolved_hint' => 'Fix the key configuration in .env. Do NOT clear :previous_key while fixing it — a key removed from the chain cannot be recovered.',
        'no_dedicated_key' => 'No :primary_key is set, so sensitive settings and 2FA secrets are encrypted with :app_key; `php artisan key:generate` on a server migration will make them unreadable, and the failure is silent.',
        'no_dedicated_key_hint' => 'Adopt a dedicated key with `php artisan encryption:key` (it keeps :app_key in the read chain, so nothing breaks), then follow :docs_link.',
        'rotation_unfinished' => 'A dedicated :primary_key is in use, but :previous_key still holds at least one key, so a rotation is unfinished and the old key still has to travel with this app.',
        'rotation_unfinished_hint' => 'Run `php artisan encryption:rekey`, then `php artisan encryption:health`; clear :previous_key only after health reports it is safe.',
        'dedicated_key_active' => 'A dedicated :primary_key is in use with no previous keys pending, so every NEW encrypted value is written with it. Run `php artisan encryption:health` to confirm no existing row is still on :app_key before rotating it.',
    ],

    // DatabaseConnectionCheck
    'database_connection' => [
        'name' => 'Database Connection',
        'connected' => 'Connection successful (:driver: :database).',
        'connection_failed' => 'Could not connect to the database: :error',
        'connection_failed_hint' => 'Check DB_HOST, DB_DATABASE, DB_USERNAME, and DB_PASSWORD in your .env file.',
    ],

    // FileManagerDiskCheck
    'file_manager_disk' => [
        'name' => 'FileManager Disk',
        'disk_undefined' => 'FileManager disk ":disk" is not defined in filesystems.disks.',
        'disk_undefined_hint' => 'Set FILESYSTEM_DISK in your .env file or choose a valid disk under Settings → Storage.',
        'root_not_writable' => 'FileManager disk ":disk" (:driver) root directory is not writable: :root.',
        'root_not_writable_hint' => 'Fix directory permissions (chmod/chown) so the web server user can write.',
        'accessible' => 'FileManager disk ":disk" (:driver) is accessible.',
        'root_missing' => 'FileManager disk ":disk" (:driver) root directory not found: :root.',
        'root_missing_hint' => 'Run php artisan storage:link.',
        'configured' => 'FileManager disk ":disk" (:driver) is configured.',
        's3_no_bucket' => 'No bucket configured for S3 disk ":disk".',
        's3_no_bucket_hint' => 'Set AWS_BUCKET in your .env file.',
        's3_accessible' => 'S3 disk ":disk" (bucket: :bucket) is accessible.',
        's3_inaccessible' => 'S3 disk ":disk" (bucket: :bucket) is not accessible: :error',
        's3_inaccessible_hint' => 'Check AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET, and AWS_DEFAULT_REGION. IAM policy must include s3:GetBucketLocation and s3:HeadBucket permissions.',
    ],

    // LogChannelCheck
    'log_channel' => [
        'name' => 'Log Channel',
        'single_unbounded' => 'LOG_CHANNEL=single — all logs go to one file and it will grow unbounded.',
        'single_unbounded_hint' => 'Set LOG_CHANNEL=daily or LOG_CHANNEL=stack in .env.',
        'configured' => 'LOG_CHANNEL=":channel".',
    ],

    // LogStackCheck
    'log_stack' => [
        'name' => 'Log Stack',
        'unrotated' => 'Active log channel ":channel" writes through an unrotated "single" driver (:channels) — logs grow unbounded.',
        'unrotated_hint_stack' => 'Set LOG_STACK=daily in .env to enable automatic log rotation.',
        'unrotated_hint_named_stack' => 'Replace the "single" member of logging.channels.:channel.channels with "daily" (LOG_STACK only configures the framework\'s own "stack" channel).',
        'unrotated_hint_default' => 'Set LOG_CHANNEL=daily in .env to enable automatic log rotation.',
        'no_unrotated' => 'Active log channel ":channel" (:channels) — no unrotated "single" driver in use.',
    ],

    // MailDriverCheck
    'mail_driver' => [
        'name' => 'Mail Driver',
        'log_array_production' => 'Mail driver is ":transport" in production — emails cannot be sent.',
        'log_array_production_hint' => 'Set MAIL_MAILER=smtp or use a driver such as Mailgun/SES.',
        'log_array_non_production' => 'Mail driver ":transport" — not suitable for production.',
        'log_array_non_production_hint' => 'Set MAIL_MAILER=smtp or use a driver such as Mailgun/SES.',
        'smtp_host_missing' => 'SMTP host is not configured.',
        'smtp_host_missing_hint' => 'Set MAIL_HOST in your .env file.',
        'smtp_unreachable' => 'Could not connect to SMTP server (:host::port): :error.',
        'smtp_unreachable_hint' => 'Check MAIL_HOST, MAIL_PORT, MAIL_USERNAME, and MAIL_PASSWORD.',
        'smtp_connected' => 'SMTP connection successful (:host::port).',
        'configured' => 'Mail driver ":transport" is configured.',
    ],

    // NodeVersionCheck
    'node_version' => [
        'name' => 'Node Version',
        'exec_failed' => 'Could not execute "node -v": :error',
        'exec_failed_hint' => 'Install Node.js :min_label to build frontend assets.',
        'not_installed' => 'Node.js is not installed or not available in PATH.',
        'not_installed_hint' => 'Install Node.js :min_label — the frontend build (vite/npm) requires it.',
        'parse_failed' => 'Could not parse Node.js version from output: ":raw".',
        'parse_failed_hint' => 'Verify your Node.js installation with node -v.',
        'below_floor' => 'Node.js :version is below the frontend toolchain floor (Vite 7 needs Node :min_label).',
        'below_floor_hint' => 'Upgrade Node.js to :min_label (e.g. via nvm) before building assets.',
        'meets_floor' => 'Node.js :version meets the frontend toolchain minimum requirement (Node :min_label).',
    ],

    // NpmBuildArtifactsCheck
    'npm_build_artifacts' => [
        'name' => 'NPM Build Artifacts',
        'manifest_missing_production' => 'public/build/manifest.json not found — frontend build is missing.',
        'manifest_missing_production_hint' => 'Run npm run build.',
        'manifest_missing' => 'public/build/manifest.json not found.',
        'manifest_missing_hint' => 'Run npm run build or npm run dev.',
        'manifest_invalid' => 'public/build/manifest.json is invalid or empty.',
        'manifest_invalid_hint' => 'Run npm run build again.',
        'present' => 'NPM build artifacts present (:count assets).',
    ],

    // PassportKeysCheck
    'passport_keys' => [
        'name' => 'Passport Keys',
        'not_installed' => 'Laravel Passport is not installed — key check skipped.',
        'not_installed_hint' => 'Ignore this warning if you are not using Passport.',
        'missing' => 'Missing Passport key file(s): :files.',
        'missing_hint' => 'Run php artisan passport:keys.',
        'unreadable' => 'Unreadable Passport key file(s): :files.',
        'unreadable_hint' => 'Fix permissions with chmod 600 storage/oauth-*.key.',
        'readable' => 'Passport key files exist and are readable.',
    ],

    // PermissionResourcesDriftCheck
    'permission_resources_drift' => [
        'name' => 'Permission Matrix',
        'package_matrix_unreadable' => 'The package copy of config/permission-resources.php could not be read.',
        'package_matrix_unreadable_hint' => 'Re-install the package files (`composer update lvntr/laravel-starter-kit`), then run `php artisan sk:doctor --only=permission-matrix` again.',
        'app_matrix_missing' => 'config/permission-resources.php is missing or empty — no permissions can be generated.',
        'app_matrix_missing_hint' => 'Run `php artisan sk:publish --tag=config`, then `php artisan sk:seed-permissions`.',
        'covered' => 'config/permission-resources.php covers every resource and ability the package ships.',
        'missing' => ':count permission(s) the package ships are absent from your matrix: :items:suffix',
        'missing_hint' => 'sk:update never touches config/permission-resources.php — it is yours. Add the entries above by hand, then run `php artisan sk:seed-permissions`.',
    ],

    // PhpExtensionsCheck
    'php_extensions' => [
        'name' => 'PHP Extensions',
        'all_loaded' => 'All required PHP extensions are loaded (:extensions).',
        'missing' => 'Missing extensions: :extensions.',
        'missing_hint' => 'Enable the extensions in your php.ini or install them via your package manager.',
    ],

    // QueueDriverCheck
    'queue_driver' => [
        'name' => 'Queue Driver',
        'sync_production' => 'Queue driver is "sync" in production — jobs run inside the HTTP request.',
        'sync_production_hint' => 'Set QUEUE_CONNECTION=redis or database and start queue:work.',
        'sync_non_production' => 'Queue driver ":driver" — not suitable for production.',
        'sync_non_production_hint' => 'Set QUEUE_CONNECTION=redis or database.',
        'database_active' => 'Queue driver ":driver" is active.',
        'database_error' => 'Queue driver ":driver" but DB access failed: :error',
        'database_error_hint' => 'Run php artisan queue:table && php artisan migrate.',
        'redis_active' => 'Queue driver ":driver" is active and connection successful.',
        'redis_error' => 'Queue driver ":driver" but Redis connection failed: :error',
        'redis_error_hint' => 'Make sure the Redis server is running.',
        'configured' => 'Queue driver ":driver" is configured.',
    ],

    // QueueWorkerCheck
    'queue_worker' => [
        'name' => 'Queue Worker',
        'sync' => 'Queue driver is "sync" — jobs run inline; no worker process is required.',
        'async_unverifiable' => 'Queue driver ":driver" is async — worker liveness cannot be verified automatically.',
        'async_unverifiable_hint' => 'Ensure a worker is running: php artisan queue:work (Supervisor or Horizon in production).',
        'horizon_unreadable' => 'Horizon is installed but its status could not be read: :error',
        'horizon_unreadable_hint' => 'Verify the Redis connection Horizon uses, then run php artisan horizon:status.',
        'horizon_no_master' => 'Horizon is installed but no master supervisor is running — queued jobs are not being processed.',
        'horizon_no_master_hint' => 'Start Horizon: php artisan horizon (Supervisor or a systemd unit in production).',
        'horizon_paused' => 'Horizon is running but every master supervisor is paused — no jobs are being processed.',
        'horizon_paused_hint' => 'Resume processing: php artisan horizon:continue.',
        'horizon_running' => 'Horizon is running (:count master supervisor(s)) — the queue is being processed.',
        'database_table_missing' => 'Queue table ":table" does not exist — jobs cannot be persisted.',
        'database_table_missing_hint' => 'Run php artisan queue:table && php artisan migrate.',
        'database_empty' => 'No pending jobs are waiting — the worker appears healthy (or the queue is empty).',
        'database_stale' => ':count job(s) pending; the oldest has waited :waited — the worker may be down.',
        'database_stale_hint' => 'Start or restart the queue worker: php artisan queue:work (or Supervisor/Horizon).',
        'database_healthy' => 'Pending jobs are within the expected processing window (oldest waited :waited).',
        'database_error' => 'Could not inspect the queue backlog: :error',
        'database_error_hint' => 'Verify the queue database connection and jobs table.',
    ],

    // RedisConnectionCheck
    'redis_connection' => [
        'name' => 'Redis Connection',
        'not_used' => 'Cache or session is not using Redis (cache=:cache, session=:session).',
        'not_used_hint' => 'Redis is recommended in production: CACHE_STORE=redis, SESSION_DRIVER=redis.',
        'connected' => 'Redis connection successful (:host::port).',
        'connection_failed' => 'Could not connect to Redis: :error',
        'connection_failed_hint' => 'Check REDIS_HOST, REDIS_PORT, and REDIS_PASSWORD in your .env file.',
    ],

    // ScheduleConfiguredCheck
    'schedule_configured' => [
        'name' => 'Schedule Configured',
        'no_tasks' => 'No scheduled tasks are defined.',
        'no_tasks_hint' => 'Define tasks in routes/console.php or App\Console\Kernel.',
        'never_run' => ':count scheduled task(s) defined, but schedule:run has never been recorded.',
        'never_run_hint' => 'The system cron entry may be missing. Add: * * * * * php artisan schedule:run >> /dev/null 2>&1',
        'stale' => ':count scheduled task(s) defined, but the last schedule:run was :diff.',
        'stale_hint' => 'The cron may have stopped. Verify the crontab entry runs schedule:run every minute.',
        'healthy' => ':count scheduled task(s) defined. Last run: :diff.',
        'error' => 'Could not check schedule status: :error',
        'error_hint' => 'Is the Schedule container binding correct? Try php artisan schedule:list.',
    ],

    // StorageSymlinkCheck
    'storage_symlink' => [
        'name' => 'Storage Symlink',
        'missing' => 'public/storage symlink not found.',
        'missing_hint' => 'Run php artisan storage:link.',
        'broken' => 'public/storage is a broken symlink (target not found).',
        'broken_hint' => 'Recreate it with php artisan storage:link --force.',
        'valid' => 'public/storage symlink is valid.',
    ],

    // ThemeManifestCheck
    'theme_manifest' => [
        'name' => 'Theme Manifest',
        'not_in_use' => 'Theme resolver not in use (no _active.css import) — check skipped.',
        'manifest_missing' => 'resources/css/theme/_active.css is missing (theme resolver output).',
        'manifest_missing_hint' => 'Run npm run theme:build or npm run build.',
        'traversal' => '_active.css contains an @import that escapes the theme directory (../).',
        'traversal_hint' => 'Regenerate with npm run build.',
        'present' => 'Theme manifest present (resources/css/theme/_active.css).',
    ],

    // TimezoneStorageCheck
    'timezone_storage' => [
        'name' => 'Timezone Storage',
        'non_utc' => 'Application timezone is :timezone; timestamps are being stored outside UTC and existing rows are already ambiguous.',
        'non_utc_hint' => 'Set APP_TIMEZONE=UTC. Use APP_DISPLAY_TIMEZONE or the General/user settings for display timezones.',
        'driver_not_applicable' => 'Application timezone is UTC; the database session timezone check does not apply to the :driver driver.',
        'session_unverifiable' => 'Could not verify the database session timezone even though the application timezone is UTC: the query returned no value.',
        'session_unverifiable_hint' => 'Check the database connection and the timezone key for the default connection in config/database.php.',
        'session_error' => 'Could not verify the database session timezone even though the application timezone is UTC: :error',
        'session_error_hint' => 'Check the database connection and the timezone key for the default connection in config/database.php.',
        'session_mismatch' => 'Application timezone is UTC, but the :driver session timezone is :session_timezone. TIMESTAMP columns are being written through a non-UTC session conversion, so rows on disk are offset even though the application reads them back consistently.',
        'session_mismatch_hint' => 'Set \'timezone\' => \'+00:00\' on the default :driver connection in config/database.php.',
        'healthy' => 'Application timezone is UTC and the :driver session timezone is :session_timezone; TIMESTAMP storage uses UTC without session conversion offsets.',
    ],

    // UnresolvedRouteCheck
    'unresolved_route' => [
        'name' => 'Unresolved Routes',
        'all_resolved' => 'Every route gated by check.permission / check.resource.permission resolves to a permission.',
        'found' => ':count route(s) currently pass with a warning because no permission can be derived from their name: :routes:suffix',
        'found_hint' => 'They will be DENIED once starter-kit.permissions.allow_unresolved defaults to false. Give each route a "<resource>.<action>" name with a mapped action, gate it with an explicit permission argument, or declare it under starter-kit.permissions.unrestricted_routes.',
    ],

    // WritableDirectoriesCheck
    'writable_directories' => [
        'name' => 'Writable Directories',
        'not_writable' => 'Non-writable directories: :directories.',
        'not_writable_hint' => 'Run chmod -R 775 storage bootstrap/cache.',
        'all_writable' => 'All critical directories are writable.',
    ],
];
