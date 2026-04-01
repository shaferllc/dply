<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_key');
            $table->nullableUlidMorphs('subject');
            $table->nullableUlidMorphs('resource');
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->string('severity', 32)->default('info');
            $table->string('category', 64)->nullable();
            $table->boolean('supports_in_app')->default(true);
            $table->boolean('supports_email')->default(false);
            $table->boolean('supports_webhook')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'resource_type', 'resource_id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
