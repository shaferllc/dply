<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ssh_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('public_key');
            $table->boolean('provision_on_new_servers')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'provision_on_new_servers']);
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->foreignUlid('user_ssh_key_id')->nullable()->after('server_id')->constrained('user_ssh_keys')->cascadeOnDelete();
            $table->unique(['server_id', 'user_ssh_key_id']);
        });
    }

    public function down(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'user_ssh_key_id']);
            $table->dropForeign(['user_ssh_key_id']);
            $table->dropColumn('user_ssh_key_id');
        });

        Schema::dropIfExists('user_ssh_keys');
    }
};
