<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Org-owned Git tokens — the machine-user credential a site can deploy with
 * when its creator's personal token dies or they leave the organization.
 * See docs/adr/org-owned-git-credentials.md.
 *
 * Exactly one owner: personal rows keep user_id, org rows carry
 * organization_id. Same shape as provider_credentials, minus its tolerance
 * for both being null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_provider_tokens', function (Blueprint $table) {
            $table->foreignUlid('organization_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Deploy resolution asks "healthy org tokens for this provider".
            $table->index(['organization_id', 'provider']);
        });

        // user_id was NOT NULL; an org-owned row has no user.
        DB::statement('ALTER TABLE git_provider_tokens ALTER COLUMN user_id DROP NOT NULL');

        // A row with neither owner is unreachable by every query in the app; a
        // row with both is ambiguous to the resolver. Reject both at the DB.
        DB::statement(<<<'SQL'
            ALTER TABLE git_provider_tokens
            ADD CONSTRAINT git_provider_tokens_single_owner
            CHECK (num_nonnulls(user_id, organization_id) = 1)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE git_provider_tokens DROP CONSTRAINT IF EXISTS git_provider_tokens_single_owner');

        Schema::table('git_provider_tokens', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'provider']);
            $table->dropConstrainedForeignId('organization_id');
        });

        // Rows without a user cannot exist once the column is NOT NULL again.
        DB::table('git_provider_tokens')->whereNull('user_id')->delete();
        DB::statement('ALTER TABLE git_provider_tokens ALTER COLUMN user_id SET NOT NULL');
    }
};
