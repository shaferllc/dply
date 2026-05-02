<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('organization_id')->nullable()->index();
            $table->ulid('server_id')->nullable()->index();

            $table->string('event_key', 100)->index();
            $table->string('summary', 300)->nullable();
            $table->json('payload');

            $table->string('url', 2048)->nullable();
            $table->boolean('signed')->default(false);
            $table->unsignedInteger('signed_at')->nullable();

            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('response_excerpt')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'created_at'], 'owd_server_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_webhook_deliveries');
    }
};
