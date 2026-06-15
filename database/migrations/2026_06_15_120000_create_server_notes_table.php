<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-note server notebook. Replaces the single `meta['notes']` blob with
 * first-class rows so notes can be pinned (surfaced on the server overview),
 * rendered as Markdown, and audited (who wrote/last-edited, and when).
 *
 * Existing `meta['notes']` text is backfilled into one pinned row per server
 * (so context already written stays visible on the new overview card), then the
 * legacy key is dropped from meta. created_by/updated_by are null for the
 * backfill — there is no reliable author for pre-existing free-text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->longText('body');
            $table->boolean('pinned')->default(false);
            // Nullable + nullOnDelete: deleting a user must not cascade away the
            // note itself, only its attribution. Also lets the backfill below
            // insert author-less rows.
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['server_id', 'pinned']);
        });

        $now = now();
        DB::table('servers')
            ->select(['id', 'meta'])
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunk(200, function ($servers) use ($now): void {
                foreach ($servers as $server) {
                    $meta = json_decode((string) $server->meta, true);
                    if (! is_array($meta)) {
                        continue;
                    }

                    $note = isset($meta['notes']) && is_string($meta['notes']) ? trim($meta['notes']) : '';
                    if ($note === '') {
                        continue;
                    }

                    DB::table('server_notes')->insert([
                        'id' => (string) Str::ulid(),
                        'server_id' => $server->id,
                        'body' => $note,
                        'pinned' => true,
                        'created_by_user_id' => null,
                        'updated_by_user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    unset($meta['notes']);
                    DB::table('servers')->where('id', $server->id)->update([
                        'meta' => json_encode($meta),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_notes');
    }
};
