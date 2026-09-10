<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme/plugin repos picked through a connected source-control account.
 *
 * Until now every git source was a pasted URL plus a per-source deploy key the
 * operator had to add to the repo by hand — while every other repo picker in
 * dply lets you choose from a connected GitHub/GitLab/Bitbucket account. A
 * source picked that way clones with the account's own credentials, so there is
 * no deploy key to install at all.
 *
 * Both columns are needed: GitIdentityResolver::forId() only resolves an
 * account id against a user (their personal OAuth account, or tokens owned by
 * one of their organizations), and the sync job runs on the queue with no user.
 *
 * Null source_control_account_id = the old behaviour: pasted URL + deploy key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_git_sources', function (Blueprint $table): void {
            $table->string('source_control_account_id')->nullable()->after('composer_package');
            $table->foreignUlid('connected_by_user_id')->nullable()->after('source_control_account_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_git_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('connected_by_user_id');
            $table->dropColumn('source_control_account_id');
        });
    }
};
