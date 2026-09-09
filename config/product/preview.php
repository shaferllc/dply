<?php

/*
|--------------------------------------------------------------------------
| Unified preview hostnames (Edge + BYO)
|--------------------------------------------------------------------------
|
| One label + apex pattern for managed preview URLs across engines.
| Branch/PR previews use the same double-dash qualifier style as Edge
| deployment aliases ({label}--pr-{n}, {label}--{branch}).
|
*/

return [

    'unified_hostnames' => env('DPLY_UNIFIED_PREVIEW_HOSTNAMES') !== null
        ? filter_var(env('DPLY_UNIFIED_PREVIEW_HOSTNAMES'), FILTER_VALIDATE_BOOLEAN)
        : true,

    'prefer_on_dply_apex' => env('DPLY_PREVIEW_PREFER_ON_DPLY_APEX') !== null
        ? filter_var(env('DPLY_PREVIEW_PREFER_ON_DPLY_APEX'), FILTER_VALIDATE_BOOLEAN)
        : true,

];
