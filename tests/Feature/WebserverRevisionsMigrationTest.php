<?php


namespace Tests\Feature\WebserverRevisionsMigrationTest;
use App\Models\ConfigRevision;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('legacy revision rows copy into config revisions with preserved metadata', function () {
    // The data migration has already run as part of RefreshDatabase. To
    // exercise it under realistic conditions, we recreate the legacy
    // table, insert a row that looks like one a real customer would
    // have, and re-run the migration in isolation.
    recreateLegacyTable();

    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    $profile = SiteWebserverConfigProfile::query()->create([
        'site_id' => $site->id,
        'webserver' => $site->webserver(),
        'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
        'main_snippet_body' => 'snippet',
    ]);

    $snapshot = [
        'mode' => 'layered',
        'before_body' => '',
        'main_snippet_body' => 'snippet',
        'after_body' => '',
        'full_override_body' => null,
    ];
    $legacyId = (string) Str::ulid();
    DB::table('site_webserver_config_revisions')->insert([
        'id' => $legacyId,
        'site_webserver_config_profile_id' => $profile->id,
        'user_id' => $user->id,
        'summary' => 'pre-migration snapshot',
        'snapshot' => json_encode($snapshot),
        'checksum' => 'legacychecksum',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    // Re-run only the data-migration step.
    runDataMigration();

    $copied = ConfigRevision::query()
        ->where('stream_key', 'site:'.$site->id.':webserver_config')
        ->where('checksum', 'legacychecksum')
        ->first();

    expect($copied)->not->toBeNull();
    expect($copied->kind)->toBe('webserver_config');
    expect($copied->subject_type)->toBe(Site::class);
    expect($copied->subject_id)->toBe($site->id);
    expect($copied->server_id)->toBe($server->id);
    expect($copied->user_id)->toBe($user->id);
    expect($copied->summary)->toBe('pre-migration snapshot');
    expect($copied->snapshot['mode'])->toBe('layered');
    expect($copied->snapshot['main_snippet_body'])->toBe('snippet');
});

function recreateLegacyTable(): void
{
    if (Schema::hasTable('site_webserver_config_revisions')) {
        return;
    }
    Schema::create('site_webserver_config_revisions', function ($table): void {
        $table->ulid('id')->primary();
        $table->foreignUlid('site_webserver_config_profile_id')->constrained('site_webserver_config_profiles')->cascadeOnDelete();
        $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('summary')->nullable();
        $table->json('snapshot');
        $table->string('checksum', 64);
        $table->timestamps();
    });
}

function runDataMigration(): void
{
    // Reset the migration as if not yet run, then re-run it.
    DB::table('migrations')
        ->where('migration', '2026_05_11_120100_migrate_site_webserver_revisions_into_config_revisions')
        ->delete();

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_05_11_120100_migrate_site_webserver_revisions_into_config_revisions.php',
        '--realpath' => false,
        '--force' => true,
    ]);
}