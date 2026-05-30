<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_advisor_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->string('status', 32);
            $table->string('subject_type')->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->foreignUlid('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('request_context')->nullable();
            $table->json('response')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'feature', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'feature', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_advisor_runs');
    }
};
