<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discussion threads hanging off a server note — "did we ever do this?",
 * "superseded by the new runbook". Flat (no parent_id): a note is already the
 * thread root, so replies-to-replies would just add nesting nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_note_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_note_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['server_note_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_note_comments');
    }
};
