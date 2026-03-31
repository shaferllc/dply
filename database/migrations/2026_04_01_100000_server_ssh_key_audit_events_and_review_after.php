<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_ssh_key_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 72);
            $table->string('ip_address', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->date('review_after')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropColumn('review_after');
        });

        Schema::dropIfExists('server_ssh_key_audit_events');
    }
};
