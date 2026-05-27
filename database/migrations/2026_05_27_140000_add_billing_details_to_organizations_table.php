<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move billing-entity fields (invoice email, VAT, currency, legal details)
 * from users to organizations.
 *
 * Why: subscriptions are org-scoped, so the legal entity these fields
 * describe is the organization, not the individual user. Users that
 * manage multiple orgs each need their own VAT number / invoice email
 * per org; storing on `users` made that impossible.
 *
 * Data is copied from each user to every organization they own (role =
 * `owner`). For a user that owns multiple orgs all of them get the same
 * starting values — owners can then edit each org's billing details
 * independently on the org billing page. User-level columns are kept
 * for now (deprecated) so this migration is reversible without data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('invoice_email', 255)->nullable()->after('email');
            $table->string('vat_number', 64)->nullable()->after('invoice_email');
            $table->string('billing_currency', 8)->nullable()->after('vat_number');
            $table->text('billing_details')->nullable()->after('billing_currency');
        });

        // Backfill: every user who owns at least one organization copies
        // their billing details onto each owned org that doesn't already
        // have one set. Idempotent — re-running the migration on a half-
        // populated DB won't clobber edits.
        $owners = DB::table('organization_user')
            ->where('role', 'owner')
            ->whereNotNull('user_id')
            ->select('user_id', 'organization_id')
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        $userIds = $owners->pluck('user_id')->unique()->all();
        $users = DB::table('users')
            ->whereIn('id', $userIds)
            ->select('id', 'invoice_email', 'vat_number', 'billing_currency', 'billing_details')
            ->get()
            ->keyBy('id');

        foreach ($owners as $row) {
            $user = $users[$row->user_id] ?? null;
            if (! $user) {
                continue;
            }

            $payload = array_filter([
                'invoice_email' => $user->invoice_email,
                'vat_number' => $user->vat_number,
                'billing_currency' => $user->billing_currency,
                'billing_details' => $user->billing_details,
            ], fn ($v) => $v !== null && $v !== '');

            if ($payload === []) {
                continue;
            }

            // Only fill columns that are still NULL on the org; preserve
            // any value an admin already set by hand.
            $existing = DB::table('organizations')
                ->where('id', $row->organization_id)
                ->select('invoice_email', 'vat_number', 'billing_currency', 'billing_details')
                ->first();
            if (! $existing) {
                continue;
            }

            $update = [];
            foreach ($payload as $key => $value) {
                if (($existing->{$key} ?? null) === null) {
                    $update[$key] = $value;
                }
            }
            if ($update === []) {
                continue;
            }
            $update['updated_at'] = now();

            DB::table('organizations')
                ->where('id', $row->organization_id)
                ->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['invoice_email', 'vat_number', 'billing_currency', 'billing_details']);
        });
    }
};
