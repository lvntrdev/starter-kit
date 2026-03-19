<?php

use Spatie\Activitylog\Models\Activity;

return [

    /*
     |--------------------------------------------------------------------------
     | Activity Logger Toggle
     |--------------------------------------------------------------------------
     |
     | Set to false to completely disable activity logging.
     | When disabled, no activities will be saved to the database.
     | This makes spatie/laravel-activitylog an optional feature.
     |
     */
    'enabled' => env('ACTIVITY_LOG_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | Record Retention
     |--------------------------------------------------------------------------
     |
     | When the clean-command is executed, all recording activities older than
     | the number of days specified here will be deleted.
     |
     */
    'delete_records_older_than_days' => 365,

    /*
     |--------------------------------------------------------------------------
     | Default Log Name
     |--------------------------------------------------------------------------
     |
     | If no log name is passed to the activity() helper
     | we use this default log name.
     |
     */
    'default_log_name' => 'default',

    /*
     |--------------------------------------------------------------------------
     | Auth Driver
     |--------------------------------------------------------------------------
     |
     | You can specify an auth driver here that gets user models.
     | If this is null we'll use the current Laravel auth driver.
     |
     */
    'default_auth_driver' => null,

    /*
     |--------------------------------------------------------------------------
     | Soft Deleted Models
     |--------------------------------------------------------------------------
     |
     | If set to true, the subject returns soft deleted models.
     |
     */
    'subject_returns_soft_deleted_models' => true,

    /*
     |--------------------------------------------------------------------------
     | Activity Model
     |--------------------------------------------------------------------------
     |
     | This model will be used to log activity.
     | It should implement the Spatie\Activitylog\Contracts\Activity interface
     | and extend Illuminate\Database\Eloquent\Model.
     |
     */
    'activity_model' => Activity::class,

    /*
     |--------------------------------------------------------------------------
     | Table Name
     |--------------------------------------------------------------------------
     |
     | This is the name of the table that will be created by the migration and
     | used by the Activity model shipped with this package.
     |
     */
    'table_name' => env('ACTIVITY_LOG_TABLE_NAME', 'activity_log'),

    /*
     |--------------------------------------------------------------------------
     | Database Connection
     |--------------------------------------------------------------------------
     |
     | This is the database connection that will be used by the migration and
     | the Activity model shipped with this package. In case it's not set
     | Laravel's database.default will be used instead.
     |
     */
    'database_connection' => env('ACTIVITY_LOG_DB_CONNECTION'),
];
