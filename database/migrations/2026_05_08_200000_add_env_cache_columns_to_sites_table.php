<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Stamped when the env-sync job successfully reads the live .env on the
            // server. Drives the "synced :time" pill in the Environment header card.
            $table->timestamp('env_synced_at')->nullable()->after('env_file_content');

            // 'server' = cache reflects the last successful sync-from-server.
            // 'local-edit' = cache has been edited in the UI/CLI and not yet pushed
            // back. Null = no edit history (fresh sites). Used to pick the right
            // freshness label and to flag "discovered from server" keys.
            $table->string('env_cache_origin', 16)->nullable()->after('env_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['env_synced_at', 'env_cache_origin']);
        });
    }
};
