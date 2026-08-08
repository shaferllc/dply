<?php
declare(strict_types=1);
namespace Tests\Feature\TmpDumpTest;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\{Organization,Server,Site,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
uses(RefreshDatabase::class);
test('dump env html', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create(['user_id'=>$user->id,'organization_id'=>$org->id,'ssh_private_key'=>'fake-key']);
    $site = Site::factory()->create(['server_id'=>$server->id,'user_id'=>$user->id,'organization_id'=>$org->id]);
    $site->forceFill(['env_file_content' => "APP_NAME=demo\nDB_PASSWORD=secret\nMAIL_HOST=smtp.example.com"])->save();
    $html = Livewire::actingAs($user)->test(SitesSettings::class, ['server'=>$server,'site'=>$site,'section'=>'environment'])->html();
    file_put_contents('/tmp/envdump.html', $html);
    dump('len='.strlen($html));
    dump('has Environment variables: '.(str_contains($html,'Environment variables')?'Y':'N'));
    dump('has APP_NAME: '.(str_contains($html,'APP_NAME')?'Y':'N'));
    dump('has Needs attention: '.(str_contains($html,'Needs attention')?'Y':'N'));
    dump('has No variables yet: '.(str_contains($html,'No variables yet')?'Y':'N'));
    expect(true)->toBeTrue();
});
