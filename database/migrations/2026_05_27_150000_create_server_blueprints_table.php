<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_blueprints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('source_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->json('snapshot');
            $table->timestamps();

            $table->index(['organization_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_blueprints');
    }
};
