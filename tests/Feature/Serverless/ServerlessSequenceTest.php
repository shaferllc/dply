<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\ServerlessSequenceBuilder;
use App\Services\Serverless\ServerlessSequenceDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ServerlessSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function functionsServer(): Server
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

    private function codeAction(Site $site, string $name): FunctionAction
    {
        return FunctionAction::query()->create([
            'site_id' => $site->id,
            'name' => $name,
            'kind' => FunctionAction::KIND_CODE,
            'runtime' => 'nodejs:18',
        ]);
    }

    public function test_it_defines_a_sequence_from_ordered_code_actions(): void
    {
        $server = $this->functionsServer();
        $appSite = Site::factory()->create(['server_id' => $server->id]);
        $sequenceSite = Site::factory()->create(['server_id' => $server->id]);

        $first = $this->codeAction($appSite, 'fetch');
        $second = $this->codeAction($appSite, 'transform');

        $sequence = (new ServerlessSequenceBuilder)->define($sequenceSite, 'pipeline', [$first->id, $second->id]);

        $this->assertSame(FunctionAction::KIND_SEQUENCE, $sequence->kind);
        $this->assertSame('pipeline', $sequence->name);
        $this->assertSame(['fetch', 'transform'], array_column($sequence->components, 'name'));
    }

    public function test_it_rejects_a_component_from_a_different_namespace(): void
    {
        $sequenceSite = Site::factory()->create(['server_id' => $this->functionsServer()->id]);
        $foreign = $this->codeAction(
            Site::factory()->create(['server_id' => $this->functionsServer()->id]),
            'foreign',
        );
        $local = $this->codeAction(Site::factory()->create(['server_id' => $sequenceSite->server_id]), 'local');

        $this->expectException(InvalidArgumentException::class);
        (new ServerlessSequenceBuilder)->define($sequenceSite, 'pipeline', [$local->id, $foreign->id]);
    }

    public function test_it_rejects_chaining_a_sequence_as_a_component(): void
    {
        $server = $this->functionsServer();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $code = $this->codeAction($site, 'fetch');
        $nested = FunctionAction::query()->create([
            'site_id' => $site->id,
            'name' => 'nested',
            'kind' => FunctionAction::KIND_SEQUENCE,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$code->id, $nested->id]);
    }

    public function test_it_rejects_a_sequence_shorter_than_two_actions(): void
    {
        $site = Site::factory()->create(['server_id' => $this->functionsServer()->id]);
        $only = $this->codeAction($site, 'solo');

        $this->expectException(InvalidArgumentException::class);
        (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$only->id]);
    }

    public function test_it_deploys_a_sequence_action_via_the_openwhisk_rest_api(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $server = $this->functionsServer();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $a = $this->codeAction($site, 'fetch');
        $b = $this->codeAction($site, 'transform');
        $sequence = (new ServerlessSequenceBuilder)->define($site, 'pipeline', [$a->id, $b->id]);

        $result = (new ServerlessSequenceDeployer)->deploy($sequence);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/actions/pipeline')
            && $request['exec']['kind'] === 'sequence'
            && $request['exec']['components'] === ['/_/fetch', '/_/transform']);
    }

    public function test_deploy_rejects_a_non_sequence_action(): void
    {
        $site = Site::factory()->create(['server_id' => $this->functionsServer()->id]);
        $code = $this->codeAction($site, 'fetch');

        $result = (new ServerlessSequenceDeployer)->deploy($code);

        $this->assertFalse($result['ok']);
    }
}
