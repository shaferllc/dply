<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_authorizations', function (Blueprint $table): void {
            // OAuth-style device-flow authorization records for the
            // dply CLI's `dply login` command. The CLI POSTs to
            // /api/v1/auth/device/start, the user approves the short
            // `user_code` on the web, and the CLI polls /poll until
            // it can pick up the freshly-minted ApiToken plaintext
            // exactly once.
            $table->ulid('id')->primary();

            // sha256(plaintext device_code). The plaintext lives only
            // in the CLI client; we never store it server-side.
            $table->string('device_code_hash', 64)->unique();

            // Short, human-typeable code (e.g. WXYZ-ABCD). Shown to
            // the user in the terminal so they can confirm the code
            // the browser is asking about matches what they see.
            // Uniqueness is enforced application-side against the
            // pending+unexpired subset only (see DeviceAuthorization::
            // generateUniqueUserCode) so old denied/expired rows
            // don't block a future user from drawing the same short
            // code.
            $table->string('user_code', 16)->index();

            // Set once the user clicks Approve. Until then the row is
            // pending; on /poll we return status without revealing
            // anything about the user.
            $table->ulid('user_id')->nullable();
            $table->ulid('organization_id')->nullable();

            // ApiToken row created on approval. Joined and deleted in
            // the same transaction as the device_authorization row so
            // a leaked device_code can never re-fetch the plaintext.
            $table->ulid('api_token_id')->nullable();

            // pending | authorized | denied | expired
            $table->string('status', 16)->default('pending');

            // Plaintext token, encrypted at rest. Returned to the CLI
            // exactly once on the first successful /poll after
            // approval, then nulled out in the same statement.
            $table->text('token_plaintext')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('api_token_id')->references('id')->on('api_tokens')->nullOnDelete();
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_authorizations');
    }
};
