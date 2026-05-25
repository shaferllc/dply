<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_site_env_vars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('value_encrypted');
            // Reserved for future preview-scoped overrides; only 'production' shipped in v1.
            $table->string('scope', 32)->default('production');
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_site_env_vars');
    }
};
