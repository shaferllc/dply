<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_systemd_service_states', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('unit', 255);
            $table->string('label', 255);
            $table->string('active_state', 64)->default('');
            $table->string('sub_state', 64)->default('');
            $table->text('active_enter_ts')->nullable();
            $table->string('version', 128)->default('');
            $table->boolean('is_custom')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestampTz('captured_at');
            $table->timestamps();

            $table->unique(['server_id', 'unit']);
            $table->index(['server_id', 'captured_at']);
        });

        Schema::create('server_systemd_service_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('kind', 32);
            $table->string('unit', 255);
            $table->string('label', 255)->default('');
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_systemd_service_audit_events');
        Schema::dropIfExists('server_systemd_service_states');
    }
};
