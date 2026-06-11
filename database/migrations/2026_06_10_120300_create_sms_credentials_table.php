<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provider slug: twilio | vonage | fcm.
            $table->string('provider');
            $table->string('name');

            // Provider-specific secrets stored as an encrypted JSON blob:
            //   twilio → {sid, auth_token, from}
            //   vonage → {key, secret, from}
            //   fcm    → {server_key}
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_credentials');
    }
};
