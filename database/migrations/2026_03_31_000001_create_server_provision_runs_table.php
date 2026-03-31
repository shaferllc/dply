<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_provision_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('task_runner_tasks')->nullOnDelete();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('status')->default('pending');
            $table->string('rollback_status')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_provision_runs');
    }
};
