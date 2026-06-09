<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beta_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The invite is bound to this address: the register form is locked to
            // it, and only a fresh account at this email may redeem.
            $table->string('email')->index();
            $table->string('token', 64)->unique();
            // How the invite was issued (admin single/bulk entry, or pulled from
            // the coming-soon waitlist) — for funnel attribution.
            $table->string('source')->nullable();
            $table->timestamp('expires_at');
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOndelete();
            // Redemption: stamped when a new account at `email` registers through
            // the token; the resulting org is flagged beta.
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignUlid('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            // Revocation: a revoked invite can no longer be redeemed but is kept
            // for audit rather than hard-deleted.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beta_invitations');
    }
};
