<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('deploy_strategy')->default('simple')->after('post_deploy_command');
            $table->unsignedTinyInteger('releases_to_keep')->default(5)->after('deploy_strategy');
            $table->text('nginx_extra_raw')->nullable()->after('releases_to_keep');
            $table->unsignedSmallInteger('octane_port')->nullable()->after('nginx_extra_raw');
            $table->boolean('laravel_scheduler')->default(false)->after('octane_port');
            $table->string('deployment_environment')->default('production')->after('laravel_scheduler');
            $table->string('php_fpm_user')->nullable()->after('deployment_environment');
        });

        Schema::create('site_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('folder', 32);
            $table->string('git_sha', 64)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'folder']);
            $table->index(['site_id', 'is_active']);
        });

        Schema::create('site_environment_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('env_key', 128);
            $table->text('env_value')->nullable();
            $table->string('environment', 32)->default('production');
            $table->timestamps();

            $table->unique(['site_id', 'env_key', 'environment']);
        });

        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('from_path', 512);
            $table->string('to_url', 1024);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('site_deploy_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('phase', 32);
            $table->text('script');
            $table->timestamps();
        });

        Schema::create('supervisor_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('slug', 64);
            $table->string('program_type', 32);
            $table->text('command');
            $table->string('directory', 512);
            $table->string('user', 64)->default('www-data');
            $table->unsignedTinyInteger('numprocs')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['server_id', 'slug']);
        });

        Schema::create('server_firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('port');
            $table->string('protocol', 8)->default('tcp');
            $table->string('action', 8)->default('allow');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('server_authorized_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('public_key');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->longText('script');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_recipes');
        Schema::dropIfExists('server_authorized_keys');
        Schema::dropIfExists('server_firewall_rules');
        Schema::dropIfExists('supervisor_programs');
        Schema::dropIfExists('site_deploy_hooks');
        Schema::dropIfExists('site_redirects');
        Schema::dropIfExists('site_environment_variables');
        Schema::dropIfExists('site_releases');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'deploy_strategy',
                'releases_to_keep',
                'nginx_extra_raw',
                'octane_port',
                'laravel_scheduler',
                'deployment_environment',
                'php_fpm_user',
            ]);
        });
    }
};
