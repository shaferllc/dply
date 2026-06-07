<?php

return [
    /*
     * dply-managed realtime log drain endpoint. When a site attaches the
     * dply_realtime provider dply injects LOG_CHANNEL=papertrail with these
     * values, using Laravel's built-in Papertrail driver — no extra packages.
     */
    'dply_realtime' => [
        'host' => env('DPLY_LOG_DRAIN_HOST', ''),
        'port' => env('DPLY_LOG_DRAIN_PORT', ''),
    ],
];
