<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_suggestions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('description');
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('status', 32)->default('new');
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_suggestions');
    }
};
