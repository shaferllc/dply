<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin allow list (production / staging)
    |--------------------------------------------------------------------------
    |
    | Comma-separated user emails that may open the Admin menu, Pulse, and the
    | custom /admin dashboard. In local and testing environments, all
    | authenticated users are treated as platform admins unless you override
    | this in config during tests.
    |
    */

    'allowed_emails' => env('PLATFORM_ADMIN_EMAILS', ''),

];
