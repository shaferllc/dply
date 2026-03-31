<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Main dply application (BYO) — shared identity & Fortify auth
    |--------------------------------------------------------------------------
    |
    | The WordPress product surface uses the same accounts as the primary app.
    | Set DPLY_MAIN_APP_URL to the main site origin (e.g. https://dply.test).
    | Auth links (login, register, dashboard) point there.
    |
    */

    'main_app_url' => rtrim((string) env('DPLY_MAIN_APP_URL', 'http://localhost'), '/'),

];
