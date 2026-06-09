<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A background process attached to a Cloud container site — either a
 * queue worker or the Laravel scheduler.
 *
 * v1 backend is DigitalOcean App Platform, which supports `workers`
 * components (long-running, no HTTP) in the same app spec as the web
 * `service`. Each CloudWorker row becomes one such component, built
 * from the same source/image as the web service. AWS App Runner has
 * no equivalent (it is HTTP-request-driven only) and is blocked.
 *
 * The scheduler is modelled as a worker with `type = scheduler` whose
 * command runs `php artisan schedule:work`; App Platform has no native
 * cron, so a long-running scheduler loop is the way to get one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_workers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            // worker | scheduler
            $table->string('type', 16)->default('worker');
            $table->string('name');
            // The run command for the component (e.g. "php artisan queue:work").
            $table->string('command')->default('');
            // Portable size tier slug — small | medium | large | xlarge.
            $table->string('size', 32)->default('small');
            $table->unsignedInteger('instance_count')->default(1);
            $table->string('status', 32)->default('provisioning');
            // Catch-all for non-secret provisioning detail + last error.
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_workers');
    }
};
