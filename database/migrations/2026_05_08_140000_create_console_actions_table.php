<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Polymorphic subject — Site, Server, or anything else that has a
            // workspace page where a backgrounded action should surface a banner.
            $table->ulidMorphs('subject');

            // Slug naming what the action does. Example: 'webserver_config',
            // 'basic_auth_sync', 'ssl', 'systemd'. Used to look up display copy
            // and to enforce one-in-flight-per-(subject, kind).
            $table->string('kind', 64);

            // queued -> running -> completed | failed. ShouldBeUnique on the
            // job class is what prevents simultaneous runs of the same kind.
            $table->string('status', 16);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Set when the operator clicks "Dismiss". The banner reader filters
            // dismissed rows out so the page goes quiet, but the row stays for
            // audit / debugging — useful when an apply ran a week ago and
            // someone needs to know what its transcript was.
            $table->timestamp('dismissed_at')->nullable();

            // Short error string when status='failed' (one-line summary). The
            // full transcript lives in `output` and may carry the stack/console.
            $table->text('error')->nullable();

            // Versioned wrapper {v: 1, lines: [{t, level, source, line}, ...]}.
            // Capped at config('console_actions.max_lines') by trim-on-append so
            // a chatty run can't unbounded-grow the row.
            $table->json('output')->nullable();

            // Who triggered this. Nullable so system-driven dispatchers (cron,
            // hooks) can still write rows without faking a user.
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The banner-reader query: latest non-dismissed run for this
            // subject. Index on (subject_type, subject_id, dismissed_at, created_at)
            // covers it cheaply.
            $table->index(['subject_type', 'subject_id', 'dismissed_at', 'created_at'], 'console_actions_subject_lookup_idx');

            // For ShouldBeUnique-style "is there an in-flight kind?" checks.
            $table->index(['subject_type', 'subject_id', 'kind', 'status'], 'console_actions_subject_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_actions');
    }
};
