<?php

declare(strict_types=1);

namespace Tests\Feature\DocsAskTest;

use App\Livewire\Docs\Sidebar;
use App\Models\Organization;
use App\Models\User;
use App\Services\Docs\DocsAskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('global.ai_llm');

test('docs ask returns answer when llm configured', function () {
    config([
        'dply_ai.llm.enabled' => true,
        'dply_ai.llm.api_key' => 'test-key',
        'dply_ai.features.docs_ask' => true,
    ]);

    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'answer' => 'Use Scan load to refresh attribution.',
                        'confidence' => 'high',
                        'cited_headings' => ['Attribution scan'],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 8],
        ], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Feature::define('global.ai_llm', fn () => true);
    Feature::flushCache();

    $result = app(DocsAskService::class)->ask(
        organization: $org,
        user: $user,
        slug: 'sites-and-deploy',
        question: 'How do I scan load?',
        routeName: 'servers.shared-host',
    );

    expect($result['error'])->toBeNull();
    expect($result['answer'])->toContain('Scan load');
});

test('docs sidebar exposes ask form when ai enabled', function () {
    config([
        'dply_ai.llm.enabled' => true,
        'dply_ai.llm.api_key' => 'test-key',
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Feature::define('global.ai_llm', fn () => true);
    Feature::flushCache();

    $this->actingAs($user);

    Livewire::test(Sidebar::class)
        ->call('open', slug: 'sites-and-deploy')
        ->assertSee(__('Ask about this page'));
});
