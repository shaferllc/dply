<?php

declare(strict_types=1);

use App\Jobs\MigrateLegacySchedulersJob;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\SchedulerWrapperScript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every scheduler becomes a wrapped cron entry plus a heartbeat. The work needs
 * SSH (the wrapper must be on the box before any line points at it), so this
 * only queues one job per affected server.
 *
 * The `laravel_scheduler` column and the synchronizer's bare block stay until
 * a later release, so any site this can't convert keeps its scheduler.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sites')->where('laravel_scheduler', true)->pluck('server_id')
            ->merge(DB::table('server_cron_jobs')
                ->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)
                ->orWhere('command', 'like', SchedulerWrapperScript::REMOTE_PATH.' %')
                ->pluck('server_id'))
            ->filter()
            ->unique()
            ->each(fn ($serverId) => MigrateLegacySchedulersJob::dispatch((string) $serverId));
    }

    public function down(): void {}
};
