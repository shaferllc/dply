<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_systemd_service_states', function (Blueprint $table): void {
            // Optimistic-intent fields. When the user clicks Start / Stop /
            // Restart / Reload, the Livewire handler writes the action and
            // its timestamp here BEFORE the SSH job runs, so the row can
            // immediately render "Starting…" / "Stopping…" / etc. The
            // recorder clears these once the next inventory sync confirms
            // the new active_state. A safety expiry (~3 min) guards against
            // SSH failures that never re-sync, so the pill auto-clears.
            $table->string('pending_action', 32)->nullable()->after('main_pid');
            $table->timestampTz('pending_action_at')->nullable()->after('pending_action');
        });
    }

    public function down(): void
    {
        Schema::table('server_systemd_service_states', function (Blueprint $table): void {
            $table->dropColumn(['pending_action', 'pending_action_at']);
        });
    }
};
