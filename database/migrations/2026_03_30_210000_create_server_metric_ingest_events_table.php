<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metric_ingest_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id')->nullable()->index();
            $table->string('organization_id', 26)->index();
            $table->string('server_id', 26)->index();
            $table->string('server_name')->nullable();
            $table->timestampTz('captured_at')->index();
            $table->json('metrics');
            $table->timestamps();

            $table->index(['organization_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_ingest_events');
    }
};
