<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_provider_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id')->nullable();
            $table->string('label')->nullable();
            $table->string('nickname')->nullable();
            $table->text('access_token');
            $table->string('api_base_url')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_provider_tokens');
    }
};
