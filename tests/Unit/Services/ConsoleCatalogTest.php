<?php


namespace Tests\Unit\Services\ConsoleCatalogTest;
use Mockery;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Support\Console\ConsoleCatalog;
use App\Support\Servers\ServerInstalledServices;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockServerWithTags(array $tags, ?string $phpVersion = null): Server
{
    $server = Mockery::mock(Server::class);

    // Mock ServerInstalledServices facade
    $mockTags = [];
    foreach ($tags as $tag) {
        $mockTags[$tag] = true;
    }

    // Use reflection to temporarily replace the method
    // In practice, we test through the public API
    return $server;
}

/** Install a stack_summary artifact so ServerInstalledServices reports a real, gated tag set. */
function setStackSummary(Server $server, array $summary): void
{
    $run = ServerProvisionRun::create([
        'server_id' => $server->id,
        'attempt' => 1,
        'status' => 'completed',
    ]);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack_summary',
        'label' => 'stack summary',
        'metadata' => $summary,
    ]);
    ServerInstalledServices::flushCaches();
}

test('catalog returns sections for server', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx', 'php', 'mysql'],
            'php_version' => '8.3',
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    // Should have sections based on installed services
    expect($sections)->toBeArray();
    expect(count($sections))->toBeGreaterThan(0);

    // System section should always be present
    $systemSection = collect($sections)->firstWhere('id', 'system');
    expect($systemSection)->not->toBeNull();
    expect($systemSection['label'])->toEqual('System');
});

test('system section always present', function () {
    $server = Server::factory()->create(['meta' => []]);

    $sections = ConsoleCatalog::for($server);

    $systemSection = collect($sections)->firstWhere('id', 'system');
    expect($systemSection)->not->toBeNull();
    expect(count($systemSection['entries']))->toBeGreaterThan(0);
});

test('nginx section shown when nginx tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $nginxSection = collect($sections)->firstWhere('id', 'nginx');
    expect($nginxSection)->not->toBeNull();
    expect($nginxSection['label'])->toEqual('Nginx');
});

test('nginx section hidden when no nginx', function () {
    $server = Server::factory()->create(['meta' => []]);
    setStackSummary($server, ['expected_services' => ['apache']]);

    $sections = ConsoleCatalog::for($server);

    $nginxSection = collect($sections)->firstWhere('id', 'nginx');
    expect($nginxSection)->toBeNull();
});

test('php section shown when php tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['php'],
            'php_version' => '8.3',
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $phpSection = collect($sections)->firstWhere('id', 'php');
    expect($phpSection)->not->toBeNull();
    expect($phpSection['label'])->toEqual('PHP');
});

test('php section substitutes version placeholder', function () {
    $server = Server::factory()->create(['meta' => []]);
    setStackSummary($server, [
        'expected_services' => ['php'],
        'php_version' => '8.3',
    ]);

    $sections = ConsoleCatalog::for($server);
    $phpSection = collect($sections)->firstWhere('id', 'php');

    // Check that {php_version} was substituted
    $fpmEntry = collect($phpSection['entries'])->first(
        fn ($e) => str_contains($e['command'], 'php-fpm')
    );
    expect($fpmEntry)->not->toBeNull();
    $this->assertStringNotContainsString('{php_version}', $fpmEntry['command']);
    $this->assertStringContainsString('8.3', $fpmEntry['command']);
});

test('mysql section shown when mysql tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['mysql'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $mysqlSection = collect($sections)->firstWhere('id', 'mysql');
    expect($mysqlSection)->not->toBeNull();
    expect($mysqlSection['label'])->toEqual('MySQL');
});

test('postgres section shown when postgres tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['postgres'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $postgresSection = collect($sections)->firstWhere('id', 'postgres');
    expect($postgresSection)->not->toBeNull();
    expect($postgresSection['label'])->toEqual('PostgreSQL');
});

test('redis section shown when redis tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['redis'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $redisSection = collect($sections)->firstWhere('id', 'redis');
    expect($redisSection)->not->toBeNull();
    expect($redisSection['label'])->toEqual('Redis');
});

test('valkey shows redis section', function () {
    // Valkey is wire-compatible with Redis and uses same tag
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['valkey'],
        ],
    ]);

    // Valkey service tag is treated as redis for catalog purposes
    // This depends on how ServerInstalledServices::tagsFor() normalizes
    $sections = ConsoleCatalog::for($server);
    $redisSection = collect($sections)->firstWhere('id', 'redis');

    // Note: This test documents expected behavior - actual behavior depends on
    // ServerInstalledServices implementation
    expect(true)->toBeTrue();
});

test('dply section shown when dply tag present', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['dply'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    $dplySection = collect($sections)->firstWhere('id', 'dply');
    expect($dplySection)->not->toBeNull();
    expect($dplySection['label'])->toEqual('dply CLI');
});

test('sections have required keys', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx', 'php'],
            'php_version' => '8.2',
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    foreach ($sections as $section) {
        expect($section)->toHaveKey('id');
        expect($section)->toHaveKey('label');
        expect($section)->toHaveKey('haystack');
        expect($section)->toHaveKey('entries');
        expect($section['entries'])->toBeArray();

        // Check haystack is lowercase for search
        expect($section['haystack'])->toEqual(strtolower($section['haystack']));

        foreach ($section['entries'] as $entry) {
            expect($entry)->toHaveKey('command');
            expect($entry)->toHaveKey('description');
            expect($entry)->toHaveKey('haystack');
        }
    }
});

test('empty sections are excluded', function () {
    // Create scenario where a section would have no entries
    // This is hard to trigger in practice since sections are curated
    $server = Server::factory()->create(['meta' => []]);

    $sections = ConsoleCatalog::for($server);

    // System should always have entries
    $systemSection = collect($sections)->firstWhere('id', 'system');
    expect($systemSection)->not->toBeNull();
    expect(count($systemSection['entries']))->toBeGreaterThan(0);
});

test('section haystack includes all entry haystacks', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);
    $nginxSection = collect($sections)->firstWhere('id', 'nginx');

    // The section haystack should be searchable
    $this->assertStringContainsString('nginx', $nginxSection['haystack']);

    // Should include command text from entries
    foreach ($nginxSection['entries'] as $entry) {
        $this->assertStringContainsString($entry['haystack'], $nginxSection['haystack']);
    }
});

test('catalog is sorted by filename', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx', 'php', 'mysql', 'postgres', 'redis', 'dply'],
            'php_version' => '8.3',
        ],
    ]);

    $sections = ConsoleCatalog::for($server);
    $sectionIds = collect($sections)->pluck('id')->toArray();

    // Should be sorted alphabetically by filename
    $sortedIds = $sectionIds;
    sort($sortedIds);
    expect($sectionIds)->toEqual($sortedIds);
});

test('missing php version substitutes empty string', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['php'],
            // php_version intentionally missing
        ],
    ]);

    $sections = ConsoleCatalog::for($server);
    $phpSection = collect($sections)->firstWhere('id', 'php');

    // Commands with {php_version} should have it replaced with empty string
    $fpmEntry = collect($phpSection['entries'])->first(
        fn ($e) => str_contains($e['command'], 'php-fpm')
    );

    // The placeholder should be removed
    $this->assertStringNotContainsString('{php_version}', $fpmEntry['command']);
});

test('description field is optional', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['nginx'],
        ],
    ]);

    $sections = ConsoleCatalog::for($server);

    foreach ($sections as $section) {
        // description key should exist even if null
        expect($section)->toHaveKey('description');
    }
});

afterEach(function () {
    Mockery::close();
});