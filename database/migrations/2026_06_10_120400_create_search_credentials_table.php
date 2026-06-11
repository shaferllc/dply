<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provider slug: algolia | meilisearch | typesense.
            $table->string('provider');
            $table->string('name');

            // Encrypted JSON, provider-shaped:
            //   algolia     → {app_id, secret}
            //   meilisearch → {host, key}
            //   typesense   → {host, port, protocol, api_key}
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_credentials');
    }
};
