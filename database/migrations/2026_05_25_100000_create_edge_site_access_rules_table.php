<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_site_access_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 32)->default('off');
            $table->string('password_hash')->nullable();
            $table->string('password_salt', 64)->nullable();
            $table->string('password_verifier', 64)->nullable();
            $table->string('cookie_secret', 64);
            $table->jsonb('allowed_emails')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_site_access_rules');
    }
};
