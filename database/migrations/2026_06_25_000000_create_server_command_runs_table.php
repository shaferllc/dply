<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_command_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            // Set for saved-command (recipe) runs; null for ad-hoc one-offs.
            $table->foreignUlid('server_recipe_id')->nullable()->constrained()->nullOnDelete();
            // 'adhoc' | 'recipe'
            $table->string('source', 16)->default('adhoc');
            // The exact shell handed to the SSH layer (already container-scoped).
            $table->longText('command');
            // What the operator typed / the recipe name — surfaced in the UI/audit.
            $table->text('display_command');
            // Optional docker-container scope captured at queue time.
            $table->string('container_scope_id')->nullable();
            $table->string('container_scope_name')->nullable();
            // 'queued' | 'running' | 'completed' | 'failed'
            $table->string('status', 16)->default('queued');
            $table->integer('exit_code')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->foreignUlid('queued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_command_runs');
    }
};
