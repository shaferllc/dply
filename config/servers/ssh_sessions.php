<?php

return [

    /*
    | Maximum contractor session duration when granting from the access graph.
    */
    'max_duration_hours' => (int) env('DPLY_SSH_SESSION_MAX_HOURS', 168),

    /*
    | Preset expiry options shown in the grant-session form (hours).
    */
    'duration_presets' => [4, 8, 24, 72, 168],

];
