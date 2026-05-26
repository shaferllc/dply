<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-shot deploy task attached to a Cloud container Site. Where
 * cloud_workers describe long-lived background processes, deploy tasks
 * are short-lived runs tied to the deploy lifecycle: migrations before
 * traffic flips, cache warmers after, cleanup on failure, or ad-hoc
 * commands the operator triggers from the dashboard.
 *
 * Each task maps to a `jobs` component in the DigitalOcean App Platform
 * spec with a `kind` matching one of the four DO triggers. AWS App
 * Runner has no equivalent; tasks are rejected on that backend.
 *
 * Laravel migrations are persisted as a regular row with name='migrate'
 * and trigger='pre_deploy' — there's no special-cased boolean field, so
 * the first-class "Run migrations on deploy" UI control and the extras
 * repeater write to the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_deploy_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            // pre_deploy | post_deploy | failed_deploy | manual
            $table->string('trigger', 24);
            $table->string('name');
            // The run command (e.g. "php artisan migrate --force"). Must
            // be executable inside the same image the web service runs;
            // empty for image-mode deploys where the user has to declare
            // a command explicitly.
            $table->string('command', 1000)->default('');
            // Portable size tier — small | medium | large | xlarge plus
            // their *-pro Professional variants. Backend adapters map to
            // their own slug taxonomies.
            $table->string('size', 32)->default('small');
            $table->string('status', 32)->default('configured');
            // Catch-all for non-secret provisioning detail + last error.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_tasks');
    }
};
