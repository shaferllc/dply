<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();

            // database | scheduler | workers | publication | redis | queue | storage
            $table->string('type');
            // attach_existing | provision_new
            $table->string('mode')->default('attach_existing');
            // configured | pending | provisioning | error
            $table->string('status')->default('pending');

            $table->string('name')->nullable();

            // Optional pointer to the concrete resource this binding wraps
            // (e.g. target_type='server_database', target_id=<ulid>). Kept as
            // a loose string pair rather than a polymorphic relation since the
            // targets live across several unrelated tables.
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();

            // Connection variables this binding contributes to the deploy
            // environment (KEY => value). Encrypted at rest; never surfaced in
            // the editable Variables list — injected only at deploy time.
            $table->text('injected_env')->nullable();

            // Free-form metadata: provisioning params, reasons, engine, etc.
            $table->json('config')->nullable();

            $table->text('last_error')->nullable();

            $table->timestamps();

            // One binding per type per site for v1. Multiple-per-type (e.g. two
            // databases) is a follow-up that would drop this constraint.
            $table->unique(['site_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_bindings');
    }
};
