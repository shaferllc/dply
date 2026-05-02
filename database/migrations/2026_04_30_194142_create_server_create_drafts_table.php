<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_create_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Highest step the user has reached (1..4). Determines forward-nav gates;
            // back-nav is always permitted.
            $table->unsignedTinyInteger('step')->default(1);
            // Encrypted blob (Eloquent encrypted:array cast). Inflates beyond jsonb usefulness;
            // we never query inside it, so text is the right fit.
            $table->text('payload');
            // Refreshed on every save. A daily prune deletes expired rows.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_create_drafts');
    }
};
