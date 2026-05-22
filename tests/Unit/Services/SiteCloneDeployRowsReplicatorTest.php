<?php


namespace Tests\Unit\Services\SiteCloneDeployRowsReplicatorTest;
use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteRedirect;
use App\Services\Sites\Clone\SiteCloneDeployRowsReplicator;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('replicates redirects to destination site', function () {
    $source = Site::factory()->create(['type' => SiteType::Static]);
    $dest = Site::factory()->create(['type' => SiteType::Static]);

    SiteDomain::query()->create([
        'site_id' => $source->id,
        'hostname' => 'a.example.com',
        'is_primary' => true,
    ]);
    SiteDomain::query()->create([
        'site_id' => $dest->id,
        'hostname' => 'b.example.com',
        'is_primary' => true,
    ]);

    SiteRedirect::query()->create([
        'site_id' => $source->id,
        'kind' => SiteRedirectKind::InternalRewrite,
        'from_path' => '/x',
        'to_url' => '/y',
        'status_code' => 301,
        'response_headers' => null,
        'sort_order' => 1,
    ]);

    SiteCloneDeployRowsReplicator::replicate($source->fresh(['redirects']), $dest->fresh());

    $this->assertDatabaseCount('site_redirects', 2);
    $row = SiteRedirect::query()->where('site_id', $dest->id)->first();
    expect($row)->not->toBeNull();
    expect($row->kind)->toBe(SiteRedirectKind::InternalRewrite);
    expect($row->from_path)->toBe('/x');
    expect($row->to_url)->toBe('/y');
    expect($row->response_headers)->toBeNull();
});

test('replicates http redirect response headers', function () {
    $source = Site::factory()->create(['type' => SiteType::Static]);
    $dest = Site::factory()->create(['type' => SiteType::Static]);

    SiteDomain::query()->create([
        'site_id' => $source->id,
        'hostname' => 'src.example.com',
        'is_primary' => true,
    ]);
    SiteDomain::query()->create([
        'site_id' => $dest->id,
        'hostname' => 'dst.example.com',
        'is_primary' => true,
    ]);

    $headers = [['name' => 'X-Test', 'value' => 'ok']];
    SiteRedirect::query()->create([
        'site_id' => $source->id,
        'kind' => SiteRedirectKind::Http,
        'from_path' => '/a',
        'to_url' => 'https://example.com/b',
        'status_code' => 303,
        'response_headers' => $headers,
        'sort_order' => 1,
    ]);

    SiteCloneDeployRowsReplicator::replicate($source->fresh(['redirects']), $dest->fresh());

    $row = SiteRedirect::query()->where('site_id', $dest->id)->first();
    expect($row)->not->toBeNull();
    expect($row->response_headers)->toBe($headers);
});