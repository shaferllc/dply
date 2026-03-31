<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main dply application (BYO) — shared identity & Fortify auth
    |--------------------------------------------------------------------------
    |
    | Marketing links (login, register, dashboard) point to the primary app.
    | Set DPLY_MAIN_APP_URL to that origin (e.g. https://dply.test).
    |
    */

    'main_app_url' => rtrim((string) env('DPLY_MAIN_APP_URL', 'http://localhost'), '/'),

];
