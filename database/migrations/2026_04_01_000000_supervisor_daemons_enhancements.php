<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_programs', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->nullable()->after('stdout_logfile');
            $table->unsignedSmallInteger('startsecs')->nullable()->after('priority');
            $table->unsignedSmallInteger('stopwaitsecs')->nullable()->after('startsecs');
            $table->string('autorestart', 32)->nullable()->after('stopwaitsecs');
            $table->boolean('redirect_stderr')->default(true)->after('autorestart');
            $table->string('stderr_logfile', 512)->nullable()->after('redirect_stderr');
        });

        Schema::create('organization_supervisor_program_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 64);
            $table->string('program_type', 32);
            $table->text('command');
            $table->string('directory', 512);
            $table->string('user', 64)->default('www-data');
            $table->unsignedTinyInteger('numprocs')->default(1);
            $table->json('env_vars')->nullable();
            $table->string('stdout_logfile', 512)->nullable();
            $table->string('stderr_logfile', 512)->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            $table->unsignedSmallInteger('startsecs')->nullable();
            $table->unsignedSmallInteger('stopwaitsecs')->nullable();
            $table->string('autorestart', 32)->nullable();
            $table->boolean('redirect_stderr')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('supervisor_program_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('supervisor_program_id')->nullable()->constrained('supervisor_programs')->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_program_audit_logs');
        Schema::dropIfExists('organization_supervisor_program_templates');

        Schema::table('supervisor_programs', function (Blueprint $table) {
            $table->dropColumn([
                'priority',
                'startsecs',
                'stopwaitsecs',
                'autorestart',
                'redirect_stderr',
                'stderr_logfile',
            ]);
        });
    }
};
