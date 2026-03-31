<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_database_admin_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mysql_root_username')->default('root');
            $table->text('mysql_root_password')->nullable();
            $table->string('postgres_superuser')->default('postgres');
            $table->text('postgres_password')->nullable();
            $table->boolean('postgres_use_sudo')->default(true);
            $table->timestamps();
        });

        Schema::create('server_database_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::create('server_database_extra_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_database_id')->constrained('server_databases')->cascadeOnDelete();
            $table->string('username');
            $table->text('password');
            $table->string('host')->default('localhost');
            $table->timestamps();

            $table->unique(['server_database_id', 'username', 'host']);
        });

        Schema::create('server_database_credential_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_database_id')->constrained('server_databases')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('views_remaining')->default(1);
            $table->unsignedSmallInteger('max_views')->default(1);
            $table->timestamps();

            $table->index(['expires_at']);
        });

        Schema::create('server_database_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_database_id')->constrained('server_databases')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_database_id', 'created_at']);
        });

        Schema::table('server_databases', function (Blueprint $table) {
            $table->string('mysql_charset')->nullable()->after('description');
            $table->string('mysql_collation')->nullable()->after('mysql_charset');
        });
    }

    public function down(): void
    {
        Schema::table('server_databases', function (Blueprint $table) {
            $table->dropColumn(['mysql_charset', 'mysql_collation']);
        });

        Schema::dropIfExists('server_database_backups');
        Schema::dropIfExists('server_database_credential_shares');
        Schema::dropIfExists('server_database_extra_users');
        Schema::dropIfExists('server_database_audit_events');
        Schema::dropIfExists('server_database_admin_credentials');
    }
};
