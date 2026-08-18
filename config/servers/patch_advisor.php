<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OS patch & reboot advisor
|--------------------------------------------------------------------------
|
| Read-only rollup of inventory probe data for the Patch advisor workspace.
|
*/

return [

    /** Inventory older than this is flagged stale in the UI. */
    'stale_inventory_hours' => (int) env('SERVER_PATCH_ADVISOR_STALE_HOURS', 24),

    'ui' => [
        'package_rows' => (int) env('SERVER_PATCH_ADVISOR_PACKAGE_ROWS', 40),
    ],

];
