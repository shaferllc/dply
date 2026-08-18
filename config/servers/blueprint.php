<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server blueprint (golden server)
|--------------------------------------------------------------------------
|
| Org-scoped stack snapshots captured from a ready VM and applied when
| provisioning the next server via the create wizard.
|
*/

return [

    'snapshot_version' => 1,

    'ui' => [
        'max_org_blueprints' => (int) env('SERVER_BLUEPRINT_MAX_PER_ORG', 20),
    ],

];
