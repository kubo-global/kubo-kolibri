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

];
