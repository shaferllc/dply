<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_basic_auth_users', function (Blueprint $table): void {
            // Set when a credential was discovered by syncBasicAuthFromServer
            // (it came from a .htpasswd file already on disk, not from a Dply
            // create-form). The value is the absolute path of the source file
            // on the server. NULL means a normal Dply-managed entry whose
            // htpasswd path is derived from {@see Site::basicAuthHtpasswdPathForNormalizedPath()}.
            // On removal, the apply flow uses this column to decide whether to
            // delete the line from the source file (and unlink it if empty)
            // instead of just rewriting the Dply group htpasswd.
            $table->string('source_file_path', 1024)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('site_basic_auth_users', function (Blueprint $table): void {
            $table->dropColumn('source_file_path');
        });
    }
};
