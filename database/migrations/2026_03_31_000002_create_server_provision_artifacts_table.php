<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_provision_artifacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_provision_run_id')->constrained('server_provision_runs')->cascadeOnDelete();
            $table->string('type');
            $table->string('key')->nullable();
            $table->string('label');
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['server_provision_run_id', 'type', 'key'], 'server_provision_artifacts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_provision_artifacts');
    }
};
