<?php

namespace Tests\Feature\Serverless\ServerlessNotFoundPageTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function functionApex(): string
{
    return ServerlessTestingDomains::routable()[0];
}

/**
 * Someone typing a function URL has usually never heard of dply, so the generic
 * 404 — app header, nav, "quick links" to Servers and Sites — is the wrong page
 * to put in front of them.
 */
it('explains that nothing is deployed instead of showing dply\'s own 404', function () {
    $response = $this->get('http://nothing-here-abc123.'.functionApex().'/');

    $response->assertStatus(404);
    $response->assertSee('There’s no app at this address', escape: false);
    $response->assertSee('nothing-here-abc123.'.functionApex());

    // None of the dashboard chrome the generic error page carries.
    $response->assertDontSee('Quick links:', escape: false);
    $response->assertDontSee(route('servers.index'));
});

it('still answers 404 so crawlers and uptime checks are told the truth', function () {
    // The page changed; the status deliberately did not.
    $this->get('http://nothing-here-abc123.'.functionApex().'/')->assertNotFound();
});

it('hints at the dashboard only on addresses this instance hands out', function () {
    $onOurApex = $this->get('http://missing-fn.'.functionApex().'/');
    $onOurApex->assertSee('Deployments tab', escape: false);
});

it('answers a JSON caller with JSON rather than a page', function () {
    $this->getJson('http://nothing-here-abc123.'.functionApex().'/')
        ->assertStatus(404)
        ->assertJsonPath('message', 'No serverless function answers at this address.')
        ->assertJsonPath('host', 'nothing-here-abc123.'.functionApex());
});

it('does the same for the /fn/{slug} path form', function () {
    $this->get('/fn/nothing-here-abc123')
        ->assertStatus(404)
        ->assertSee('There’s no app at this address', escape: false);
});

it('leaves a real function alone', function () {
    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => ['proxy_slug' => 'realfn-abc123'],
        ],
    ]);

    // It resolves to the site, so it does NOT render the not-found page —
    // whatever the upstream does with it is the proxy's business.
    $this->get('/fn/realfn-abc123')->assertDontSee('There’s no app at this address', escape: false);
});
