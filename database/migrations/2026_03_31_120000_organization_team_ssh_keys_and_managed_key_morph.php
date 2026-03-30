<?php

use App\Models\UserSshKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ssh_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('public_key');
            $table->boolean('provision_on_new_servers')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'provision_on_new_servers']);
        });

        Schema::create('team_ssh_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('public_key');
            $table->boolean('provision_on_new_servers')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'provision_on_new_servers']);
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->nullableUlidMorphs('managed_key');
        });

        DB::update(
            'UPDATE server_authorized_keys SET managed_key_type = ?, managed_key_id = user_ssh_key_id WHERE user_ssh_key_id IS NOT NULL',
            [UserSshKey::class]
        );

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'user_ssh_key_id']);
            $table->dropForeign(['user_ssh_key_id']);
            $table->dropColumn('user_ssh_key_id');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->unique(['server_id', 'managed_key_type', 'managed_key_id'], 'srv_auth_keys_managed_unique');
        });
    }

    public function down(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropUnique('srv_auth_keys_managed_unique');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->foreignUlid('user_ssh_key_id')->nullable()->after('server_id')->constrained('user_ssh_keys')->cascadeOnDelete();
        });

        DB::update(
            'UPDATE server_authorized_keys SET user_ssh_key_id = managed_key_id WHERE managed_key_type = ? AND managed_key_id IS NOT NULL',
            [UserSshKey::class]
        );

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropMorphs('managed_key');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->unique(['server_id', 'user_ssh_key_id']);
        });

        Schema::dropIfExists('team_ssh_keys');
        Schema::dropIfExists('organization_ssh_keys');
    }
};
