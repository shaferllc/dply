<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_preview_domains')) {
            return;
        }

        Schema::create('site_preview_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('label')->nullable();
            $table->string('zone')->nullable();
            $table->string('record_name')->nullable();
            $table->string('provider_type')->nullable();
            $table->string('provider_record_id')->nullable();
            $table->string('record_type')->nullable();
            $table->string('record_data')->nullable();
            $table->string('dns_status')->default('pending');
            $table->string('ssl_status')->default('none');
            $table->boolean('is_primary')->default(false);
            $table->boolean('auto_ssl')->default(true);
            $table->boolean('https_redirect')->default(true);
            $table->boolean('managed_by_dply')->default(true);
            $table->timestamp('last_dns_checked_at')->nullable();
            $table->timestamp('last_ssl_checked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_preview_domains');
    }
};
