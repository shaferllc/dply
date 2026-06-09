<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_site_members', function (Blueprint $table): void {
            // Per-site role grants on top of the org-level membership
            // (P12). Grants only ELEVATE rights — they never restrict
            // what an org admin can do. A user listed here as
            // `deployer` on site X gets deploy rights on X even when
            // they're only a viewer on the org.
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('user_id');
            $table->string('role', 16); // 'viewer' | 'deployer' | 'admin'
            $table->ulid('invited_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('invited_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['site_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_site_members');
    }
};
