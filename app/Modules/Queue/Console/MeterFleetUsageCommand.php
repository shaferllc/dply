<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Modules\Queue\Services\FleetUsageMeter;
use Illuminate\Console\Command;

/**
 * Rolls managed worker time into daily usage rows.
 *
 * Hourly rather than nightly, because the watermark it advances is what makes
 * a still-running worker billable: a Pro fleet that never stops would
 * otherwise accrue nothing until the day someone deleted it.
 */
class MeterFleetUsageCommand extends Command
{
    protected $signature = 'dply:queue:meter-fleets';

    protected $description = 'Roll managed queue worker time into daily usage (MiB-seconds by compute class).';

    public function handle(FleetUsageMeter $meter): int
    {
        $totals = $meter->roll();

        $this->components->twoColumnDetail('Workers metered', (string) $totals['workers']);
        $this->components->twoColumnDetail('Flex MiB-seconds', number_format($totals['flex_mib_seconds']));
        $this->components->twoColumnDetail('Pro MiB-seconds', number_format($totals['pro_mib_seconds']));

        return self::SUCCESS;
    }
}
