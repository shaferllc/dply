<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_systemd_service_states', function (Blueprint $table) {
            $table->string('unit_file_state', 64)->nullable()->after('sub_state');
            $table->string('main_pid', 32)->nullable()->after('unit_file_state');
        });

        Schema::create('server_systemd_notification_digest_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('notification_channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('digest_bucket', 32);
            $table->string('unit', 255);
            $table->string('event_kind', 32);
            $table->text('line');
            $table->timestamps();

            $table->index(['digest_bucket', 'notification_channel_id']);
            $table->index('created_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('services_preferences')->nullable()->after('insights_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('services_preferences');
        });

        Schema::dropIfExists('server_systemd_notification_digest_lines');

        Schema::table('server_systemd_service_states', function (Blueprint $table) {
            $table->dropColumn(['unit_file_state', 'main_pid']);
        });
    }
};
