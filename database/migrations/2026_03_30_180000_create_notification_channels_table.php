<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('owner');
            $table->string('type', 32);
            $table->string('label', 160);
            $table->text('config');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
