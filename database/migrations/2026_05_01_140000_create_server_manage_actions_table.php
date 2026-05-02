<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_manage_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('server_id')->index();
            $table->ulid('user_id')->nullable()->index();

            $table->string('task_name', 120);
            $table->string('label', 200);
            $table->string('status', 24)->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'created_at'], 'sma_server_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_manage_actions');
    }
};
