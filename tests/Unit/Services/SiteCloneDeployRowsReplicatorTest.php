<?php

namespace Tests\Unit\Services;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteRedirect;
use App\Services\Sites\Clone\SiteCloneDeployRowsReplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCloneDeployRowsReplicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_replicates_redirects_to_destination_site(): void
    {
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
        $this->assertNotNull($row);
        $this->assertSame(SiteRedirectKind::InternalRewrite, $row->kind);
        $this->assertSame('/x', $row->from_path);
        $this->assertSame('/y', $row->to_url);
        $this->assertNull($row->response_headers);
    }

    public function test_replicates_http_redirect_response_headers(): void
    {
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
        $this->assertNotNull($row);
        $this->assertSame($headers, $row->response_headers);
    }
}
