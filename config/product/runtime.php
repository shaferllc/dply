<?php

/*
|--------------------------------------------------------------------------
| Control-plane runtime role (web vs worker split)
|--------------------------------------------------------------------------
|
| When dply runs across dedicated web and worker VMs, set DPLY_RUNTIME on each
| box so the scheduler only registers on the primary worker and operators can
| sanity-check placement via `php artisan dply:runtime:check`.
|
|   all    — local dev / single-box installs (default)
|   web    — HTTP + Reverb; no scheduler or Horizon on this host
|   worker — Horizon; scheduler only when DPLY_WORKER_ROLE=primary
|
*/

return [
    'mode' => env('DPLY_RUNTIME', 'all'),

    /*
    | Only used when mode=worker. Primary runs schedule:work; replica is Horizon-only.
    */
    'worker_role' => env('DPLY_WORKER_ROLE', 'primary'),
];
