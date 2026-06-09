<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_apps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');
            // Public connection key; secret is encrypted at the model layer.
            $table->string('app_key')->unique();
            $table->text('app_secret');

            $table->string('status')->default('provisioning')->index();
            $table->string('backend')->default('dply_realtime');
            $table->string('host')->nullable();

            $table->unsignedInteger('max_connections')->nullable();
            // Peak concurrent connections captured from the Worker per window —
            // recorded from day one so connection-based tiers can drop in later.
            $table->unsignedInteger('peak_connections')->nullable();
            $table->timestamp('last_stats_at')->nullable();

            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_apps');
    }
};
