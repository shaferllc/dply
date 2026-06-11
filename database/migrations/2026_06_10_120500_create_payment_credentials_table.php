<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provider slug: stripe | paddle.
            $table->string('provider');
            $table->string('name');

            // Encrypted JSON, provider-shaped:
            //   stripe → {key, secret, webhook_secret?, currency?}
            //   paddle → {api_key, client_side_token, webhook_secret?, sandbox?}
            $table->text('credentials');

            $table->timestamps();

            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_credentials');
    }
};
