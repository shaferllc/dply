<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('description');
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('workspace_environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('workspace_labels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('color', 24)->default('slate');
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('workspace_label_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('workspace_label_id')->constrained('workspace_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'workspace_label_id']);
        });

        Schema::create('workspace_runbooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('url', 500)->nullable();
            $table->text('body')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('workspace_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();
        });

        Schema::create('workspace_variables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('env_key', 120);
            $table->text('env_value')->nullable();
            $table->boolean('is_secret')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'env_key']);
        });

        Schema::create('workspace_deploy_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('queued');
            $table->json('site_ids')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        $workspaceRows = DB::table('workspaces')
            ->select(['id', 'organization_id', 'user_id'])
            ->get();

        foreach ($workspaceRows as $workspace) {
            DB::table('workspace_members')->insert([
                'id' => (string) str()->ulid(),
                'workspace_id' => $workspace->id,
                'user_id' => $workspace->user_id,
                'role' => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('workspace_environments')->insert([
                [
                    'id' => (string) str()->ulid(),
                    'workspace_id' => $workspace->id,
                    'name' => 'Production',
                    'slug' => 'production',
                    'description' => 'Live production resources for this project.',
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => (string) str()->ulid(),
                    'workspace_id' => $workspace->id,
                    'name' => 'Staging',
                    'slug' => 'staging',
                    'description' => 'Pre-production validation resources.',
                    'sort_order' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => (string) str()->ulid(),
                    'workspace_id' => $workspace->id,
                    'name' => 'Development',
                    'slug' => 'development',
                    'description' => 'Internal development and testing resources.',
                    'sort_order' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_deploy_runs');
        Schema::dropIfExists('workspace_variables');
        Schema::dropIfExists('workspace_views');
        Schema::dropIfExists('workspace_runbooks');
        Schema::dropIfExists('workspace_label_assignments');
        Schema::dropIfExists('workspace_labels');
        Schema::dropIfExists('workspace_environments');
        Schema::dropIfExists('workspace_members');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
