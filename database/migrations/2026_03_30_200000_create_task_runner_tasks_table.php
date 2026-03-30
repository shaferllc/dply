<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_runner_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('action')->nullable();
            $table->text('script')->nullable();
            $table->longText('script_content')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->string('user')->nullable();
            $table->string('status');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('options')->nullable();
            $table->longText('instance')->nullable();
            $table->foreignUlid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runner_tasks');
    }
};
