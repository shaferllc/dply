<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_certificates')) {
            return;
        }

        Schema::create('site_certificates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('preview_domain_id')->nullable()->constrained('site_preview_domains')->nullOnDelete();
            $table->foreignUlid('provider_credential_id')->nullable()->constrained('provider_credentials')->nullOnDelete();
            $table->string('scope_type');
            $table->string('provider_type');
            $table->string('challenge_type');
            $table->string('dns_provider')->nullable();
            $table->string('credential_reference')->nullable();
            $table->json('domains_json');
            $table->string('status')->default('pending');
            $table->boolean('force_skip_dns_checks')->default(false);
            $table->boolean('enable_http3')->default(false);
            $table->string('certificate_path')->nullable();
            $table->string('private_key_path')->nullable();
            $table->string('chain_path')->nullable();
            $table->longText('certificate_pem')->nullable();
            $table->longText('private_key_pem')->nullable();
            $table->longText('chain_pem')->nullable();
            $table->longText('csr_pem')->nullable();
            $table->longText('last_output')->nullable();
            $table->json('requested_settings')->nullable();
            $table->json('applied_settings')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_requested_at')->nullable();
            $table->timestamp('last_installed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['scope_type', 'provider_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_certificates');
    }
};
