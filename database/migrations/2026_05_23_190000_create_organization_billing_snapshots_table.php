<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_billing_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('monthly_total_cents')->default(0);
            $table->json('category_breakdown')->nullable();
            $table->json('fleet_counts')->nullable();
            $table->unsignedInteger('edge_usage_cents')->default(0);
            $table->string('subscription_interval', 16)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'snapshot_date'], 'billing_snapshot_org_date_unique');
            $table->index(['snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_billing_snapshots');
    }
};
