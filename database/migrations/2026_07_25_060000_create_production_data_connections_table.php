<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_data_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('base_url', 512);
            $table->text('api_token');
            $table->string('remote_organization_id', 36)->nullable();
            $table->string('remote_organization_name')->nullable();
            $table->string('remote_organization_slug')->nullable();
            $table->string('remote_user_email')->nullable();
            $table->string('remote_user_name')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_data_connections');
    }
};
