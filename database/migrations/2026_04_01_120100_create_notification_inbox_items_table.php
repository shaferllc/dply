<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_inbox_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->nullableUlidMorphs('resource');
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_inbox_items');
    }
};
