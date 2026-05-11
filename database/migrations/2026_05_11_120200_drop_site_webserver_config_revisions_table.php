<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data was migrated in the preceding migration; the
     * SiteWebserverConfigRevision model and all references have been
     * removed. Drop the old table.
     */
    public function up(): void
    {
        Schema::dropIfExists('site_webserver_config_revisions');
    }

    public function down(): void
    {
        Schema::create('site_webserver_config_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_webserver_config_profile_id')->constrained('site_webserver_config_profiles')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('summary')->nullable();
            $table->json('snapshot');
            $table->string('checksum', 64);
            $table->timestamps();

            $table->index(['site_webserver_config_profile_id', 'created_at']);
        });
    }
};
