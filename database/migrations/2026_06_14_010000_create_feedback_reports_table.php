<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Human-friendly reference shown to the reporter ("report #FB-XXXXXXXX").
            $table->string('reference', 16)->unique();

            // Who + where. Nullable so a report survives the user/org being deleted.
            $table->char('user_id', 26)->nullable();
            $table->char('organization_id', 26)->nullable();

            $table->string('type', 24)->default('bug');         // bug | idea | question
            $table->string('severity', 16)->nullable();         // low | normal | high | critical (bugs only)
            $table->string('status', 24)->default('new');       // new | triaged | in_progress | resolved | closed | wont_fix | duplicate

            $table->string('title');
            $table->text('description');

            // Auto-captured browser/runtime context: url, route, user_agent,
            // viewport, app_version, locale, plus the console-error ring buffer.
            $table->json('context')->nullable();

            $table->string('screenshot_path')->nullable();
            $table->json('attachments')->nullable();            // [{path,name,size,mime}]

            $table->text('admin_notes')->nullable();
            $table->char('assigned_to_user_id', 26)->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('attachments_pruned_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
            $table->index('severity');
            $table->index('organization_id');
            $table->index('assigned_to_user_id');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reports');
    }
};
