<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server maintenance window
|--------------------------------------------------------------------------
|
| Server-scoped visitor maintenance: suspend all eligible VM sites on one
| host with a shared public message until the window is cleared.
|
*/

return [

    'suspended_reason' => 'server_maintenance',

    'meta_key' => 'maintenance',

];
