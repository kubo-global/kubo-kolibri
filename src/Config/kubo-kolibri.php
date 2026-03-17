<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kolibri Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL where Kolibri is running. On a Raspberry Pi deployment,
    | both KUBO and Kolibri run on the same machine.
    |
    */
    'kolibri_url' => env('KOLIBRI_URL', 'http://localhost:8080'),

    /*
    |--------------------------------------------------------------------------
    | Kolibri Credentials
    |--------------------------------------------------------------------------
    |
    | Superuser credentials for Kolibri API access. Used for reading content
    | metadata and student progress.
    |
    */
    'kolibri_username' => env('KOLIBRI_USERNAME', 'admin'),
    'kolibri_password' => env('KOLIBRI_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Progress Sync Interval
    |--------------------------------------------------------------------------
    |
    | How often (in minutes) to poll Kolibri for new progress data.
    | Set to 0 to disable automatic sync (manual only).
    |
    */
    'sync_interval' => env('KOLIBRI_SYNC_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Content Rendering
    |--------------------------------------------------------------------------
    |
    | How Kolibri content is rendered inside KUBO's UI.
    | 'iframe' embeds Kolibri's own renderer via iframe.
    | 'zipcontent' serves Kolibri's static content files directly.
    |
    */
    'render_mode' => env('KOLIBRI_RENDER_MODE', 'iframe'),

];
