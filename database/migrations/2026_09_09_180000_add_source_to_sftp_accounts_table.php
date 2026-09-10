<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Linux account came from.
 *
 * `created` — dply ran useradd for it, so dply owns its whole lifecycle and
 * userdel on removal is correct.
 *
 * `adopted` — the account already existed (a shell user someone made earlier)
 * and dply only added it to the dply-sftp group and granted it the site tree.
 * Removing FTP access from one of these must NOT delete the account: the
 * operator asked for file transfer, not for their user to disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_accounts', function (Blueprint $table): void {
            $table->string('source')->default('created')->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('sftp_accounts', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
