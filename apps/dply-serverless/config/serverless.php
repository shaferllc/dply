<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Function provisioner driver
    |--------------------------------------------------------------------------
    |
    | Which ServerlessFunctionProvisioner implementation the container binds.
    | Stubs today: local, aws, digitalocean. Live SDK adapters replace these later.
    |
    */

    'provisioner' => env('SERVERLESS_PROVISIONER', 'local'),

];
