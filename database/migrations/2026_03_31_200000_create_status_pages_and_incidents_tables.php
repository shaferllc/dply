<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('status_page_monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->ulidMorphs('monitorable');
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status_page_id', 'sort_order']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('impact')->default('minor'); // none, minor, major, critical
            $table->string('state')->default('investigating'); // investigating, identified, monitoring, resolved
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status_page_id', 'resolved_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('status_page_monitors');
        Schema::dropIfExists('status_pages');
    }
};
