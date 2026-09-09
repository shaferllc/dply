<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider')->unique();
            $table->text('config');
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
