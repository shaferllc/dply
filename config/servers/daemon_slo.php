<?php

declare(strict_types=1);

return [

    'stale_health_hours' => max(1, (int) env('SERVER_DAEMON_SLO_STALE_HOURS', 6)),

    'down_warning_minutes' => max(1, (int) env('SERVER_DAEMON_SLO_DOWN_WARNING_MINUTES', 5)),

];
