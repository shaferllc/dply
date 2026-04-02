<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_domain_aliases')) {
            return;
        }

        Schema::create('site_domain_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domain_aliases');
    }
};
