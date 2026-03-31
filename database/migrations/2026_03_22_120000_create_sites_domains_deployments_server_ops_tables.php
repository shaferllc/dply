<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('php'); // php, static, node
            $table->string('document_root');
            $table->string('repository_path')->nullable();
            $table->string('php_version')->nullable();
            $table->unsignedSmallInteger('app_port')->nullable();
            $table->string('status')->default('pending');
            $table->string('ssl_status')->default('none'); // none, pending, active, failed
            $table->timestamp('nginx_installed_at')->nullable();
            $table->timestamp('ssl_installed_at')->nullable();
            $table->timestamp('last_deploy_at')->nullable();
            $table->string('git_repository_url')->nullable();
            $table->string('git_branch')->default('main');
            $table->text('git_deploy_key_private')->nullable();
            $table->text('git_deploy_key_public')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('post_deploy_command')->nullable();
            $table->longText('env_file_content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'slug']);
        });

        Schema::create('site_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('hostname');
            $table->boolean('is_primary')->default(false);
            $table->boolean('www_redirect')->default(false);
            $table->timestamps();

            $table->unique('hostname');
        });

        Schema::create('site_deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('trigger'); // manual, webhook, api
            $table->string('status'); // running, success, failed
            $table->string('git_sha', 64)->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->longText('log_output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });

        Schema::create('server_databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('engine')->default('mysql'); // mysql, postgres
            $table->string('username');
            $table->text('password');
            $table->string('host')->default('127.0.0.1');
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        Schema::create('server_cron_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('cron_expression', 64);
            $table->text('command');
            $table->string('user')->default('root');
            $table->boolean('is_synced')->default(false);
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_cron_jobs');
        Schema::dropIfExists('server_databases');
        Schema::dropIfExists('site_deployments');
        Schema::dropIfExists('site_domains');
        Schema::dropIfExists('sites');
    }
};
