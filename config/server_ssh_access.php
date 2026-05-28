<?php

declare(strict_types=1);

return [

    'stale_drift_hours' => max(1, (int) env('SERVER_SSH_ACCESS_STALE_DRIFT_HOURS', 24)),

];
