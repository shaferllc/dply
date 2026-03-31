<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestampTz('captured_at');
            $table->json('payload');
            $table->timestamps();

            $table->index(['server_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_snapshots');
    }
};
