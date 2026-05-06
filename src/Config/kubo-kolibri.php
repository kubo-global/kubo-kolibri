<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kolibri Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL where Kolibri is running. KUBO and Kolibri may be on
    | separate servers — this URL must be reachable from both the KUBO
    | server (for API calls) and from student browsers (for redirects).
    |
    */
    'kolibri_url' => env('KOLIBRI_URL', 'http://localhost:8080'),

    /*
    |--------------------------------------------------------------------------
    | Kolibri Admin Credentials
    |--------------------------------------------------------------------------
    |
    | Superuser credentials for Kolibri API access. Used for provisioning
    | facilities, classrooms, and learners, and for reading progress data.
    |
    */
    'kolibri_username' => env('KOLIBRI_USERNAME', 'admin'),
    'kolibri_password' => env('KOLIBRI_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Facility ID Override
    |--------------------------------------------------------------------------
    |
    | Local-dev escape hatch. When set, takes precedence over the school row's
    | `kolibri_facility_id` for the iframe SSO flow. Use it when working
    | against a production DB dump on a laptop whose local Kolibri has a
    | different facility UUID — without modifying the dump. Leave unset in
    | production.
    |
    */
    'facility_id_override' => env('KOLIBRI_FACILITY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Learner Password Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret used to derive deterministic passwords for Kolibri
    | learners. Each KUBO student gets a Kolibri password derived from
    | this secret + their user ID, so passwords never need to be stored.
    |
    | IMPORTANT: Change this from the default in production.
    |
    */
    'learner_password_secret' => env('KOLIBRI_LEARNER_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Progress Sync Interval
    |--------------------------------------------------------------------------
    |
    | How often (in minutes) to poll Kolibri for new progress data.
    | Set to 0 to disable automatic sync (manual only via kolibri:sync).
    |
    */
    'sync_interval' => env('KOLIBRI_SYNC_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Kolibri Content Path
    |--------------------------------------------------------------------------
    |
    | Filesystem path to Kolibri's content directory. Channel databases
    | and Perseus exercise files are written here by the channel generator.
    |
    */
    'kolibri_content_path' => env('KOLIBRI_CONTENT_PATH', rtrim(env('HOME', '/var/lib/kolibri'), '/') . '/.kolibri/content'),

];
