<?php

declare(strict_types=1);

return [

    /** Meta key on servers.meta */
    'meta_key' => 'deploy_policy',

    /** Preset deny rules for “no prod deploys Fri 5pm – Mon 9am”. Days: mon..sun */
    'weekend_freeze_preset' => [
        ['days' => ['fri'], 'start' => '17:00', 'end' => '23:59'],
        ['days' => ['sat'], 'start' => '00:00', 'end' => '23:59'],
        ['days' => ['sun'], 'start' => '00:00', 'end' => '23:59'],
        ['days' => ['mon'], 'start' => '00:00', 'end' => '09:00'],
    ],

];
