<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic-ish by intent, but kept as plain columns: the command
            // palette records which resource a user drilled into (type + the
            // resource's ULID) so the empty-query state can offer "Recently
            // visited". Not a real morph — no model resolution at the DB layer.
            $table->string('resource_type');
            $table->string('resource_id');

            $table->timestamp('visited_at');

            // One row per (user, resource): a re-visit bumps visited_at.
            $table->unique(['user_id', 'resource_type', 'resource_id']);
            // The empty-state query: a user's most-recent rows.
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_resources');
    }
};
