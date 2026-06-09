<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscription_sync_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('trigger', 64);
            $table->string('status', 16);
            $table->json('changes')->nullable();
            $table->json('desired_state')->nullable();
            $table->unsignedInteger('monthly_total_cents')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'billing_sync_events_org_created_idx');
            $table->index(['organization_id', 'status'], 'billing_sync_events_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_sync_events');
    }
};
