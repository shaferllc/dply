<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessSequenceTest;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\ServerlessSequenceBuilder;
use App\Services\Serverless\ServerlessSequenceDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function functionsServer(): Server
{
    return Server::factory()->create([
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas-nyc1.example.com',
                'access_key' => 'keyid:keysecret',
            ],
        ],
    ]);
}
function codeAction(Site $site, string $name): FunctionAction
{
    return FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => $name,
        'kind' => FunctionAction::KIND_CODE,
        'runtime' => 'nodejs:18',
    ]);
}
test('it defines a sequence from ordered code actions', function () {
    $server = functionsServer();
    $appSite = Site::factory()->create(['server_id' => $server->id]);
    $sequenceSite = Site::factory()->create(['server_id' => $server->id]);

    $first = codeAction($appSite, 'fetch');
    $second = codeAction($appSite, 'transform');

    $sequence = (new ServerlessSequenceBuilder)->define($sequenceSite, 'pipeline', [$first->id, $second->id]);

    expect($sequence->kind)->toBe(FunctionAction::KIND_SEQUENCE);
    expect($sequence->name)->toBe('pipeline');
    expect(array_column($sequence->components, 'name'))->toBe(['fetch', 'transform']);
});
test('it rejects a component from a different namespace', function () {
    $sequenceSite = Site::factory()->create(['server_id' => functionsServer()->id]);
    $foreign = codeAction(Site::factory()->create(['server_id' => functionsServer()->id]), 'foreign');
    $local = codeAction(Site::factory()->create(['server_id' => $sequenceSite->server_id]), 'local');

    $this->expectException(InvalidArgumentException::class);
    (new ServerlessSequenceBuilder)->define($sequenceSite, 'pipeline', [$local->id, $foreign->id]);
});
test('it rejects chaining a sequence as a component', function () {
    $server = functionsServer();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $code = codeAction($site, 'fetch');
    $nested = FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'nested',
        'kind' => FunctionAction::KIND_SEQUENCE,
    ]);

    $this->expectException(InvalidArgumentException::class);
    (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$code->id, $nested->id]);
});
test('it rejects a sequence shorter than two actions', function () {
    $site = Site::factory()->create(['server_id' => functionsServer()->id]);
    $only = codeAction($site, 'solo');

    $this->expectException(InvalidArgumentException::class);
    (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$only->id]);
});
test('it deploys a sequence action via the openwhisk rest api', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $server = functionsServer();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $a = codeAction($site, 'fetch');
    $b = codeAction($site, 'transform');
    $sequence = (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$a->id, $b->id]);

    $result = (new ServerlessSequenceDeployer)->deploy($sequence);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/actions/pipeline')
        && $request['exec']['kind'] === 'sequence'
        && $request['exec']['components'] === ['/_/fetch', '/_/transform']);
});
test('deploy rejects a non sequence action', function () {
    $site = Site::factory()->create(['server_id' => functionsServer()->id]);
    $code = codeAction($site, 'fetch');

    $result = (new ServerlessSequenceDeployer)->deploy($code);

    expect($result['ok'])->toBeFalse();
});
