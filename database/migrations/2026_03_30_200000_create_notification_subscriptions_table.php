<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('subscribable');
            $table->string('event_key', 80);
            $table->timestamps();

            $table->unique(
                ['notification_channel_id', 'subscribable_type', 'subscribable_id', 'event_key'],
                'notification_subscriptions_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_subscriptions');
    }
};
