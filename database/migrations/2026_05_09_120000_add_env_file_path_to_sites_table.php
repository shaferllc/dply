<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Optional override for where Dply reads / writes the .env file
            // on the host. When NULL the default ($effectiveEnvDirectory/.env)
            // applies. Operators set this when they want the file outside the
            // docroot for security — e.g. /etc/dply/<slug>.env. Path must be
            // absolute; validation is enforced at the service layer.
            $table->string('env_file_path', 1024)->nullable()->after('env_cache_origin');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('env_file_path');
        });
    }
};
