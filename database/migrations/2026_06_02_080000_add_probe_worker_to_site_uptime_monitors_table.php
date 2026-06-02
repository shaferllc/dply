<?php

use App\Models\SiteUptimeMonitor;
use App\Services\Sites\UptimeProbeWorkerResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_uptime_monitors', function (Blueprint $table): void {
            // Nullable: null means "no specific worker" → dispatched onto the
            // central `default` queue (same egress as the pre-worker behaviour).
            $table->string('probe_worker', 64)->nullable()->after('probe_region');
        });

        // Backfill existing monitors to the nearest configured worker for their
        // host, so the feature takes effect immediately without per-monitor
        // edits. With a single configured worker, every row gets that worker.
        $resolver = app(UptimeProbeWorkerResolver::class);

        SiteUptimeMonitor::query()
            ->with('site.server')
            ->whereNull('probe_worker')
            ->chunkById(200, function ($monitors) use ($resolver): void {
                foreach ($monitors as $monitor) {
                    if (! $monitor->site) {
                        continue;
                    }

                    $worker = $resolver->forSite($monitor->site);
                    if ($worker === null) {
                        continue;
                    }

                    $monitor->forceFill([
                        'probe_worker' => $worker,
                        'probe_region' => $resolver->regionFor($worker) ?? $monitor->probe_region,
                    ])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('site_uptime_monitors', function (Blueprint $table): void {
            $table->dropColumn('probe_worker');
        });
    }
};
