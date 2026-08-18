<?php

declare(strict_types=1);

return [

    'warning_days' => max(1, (int) env('SERVER_CERT_INVENTORY_WARNING_DAYS', 30)),

    'critical_days' => max(1, (int) env('SERVER_CERT_INVENTORY_CRITICAL_DAYS', 7)),

];
