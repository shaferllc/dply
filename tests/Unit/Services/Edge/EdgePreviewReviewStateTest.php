<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\EdgePreviewComment;
use App\Models\EdgePreviewReviewApproval;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgePreviewReviewState;
use App\Support\Edge\EdgePreviewPullRequestLink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pull request link resolves github repo on parent site', function () {
    $preview = previewSiteForReviewState();

    expect(EdgePreviewPullRequestLink::forPreview($preview))
        ->toBe('https://github.com/acme/storefront/pull/7');
});

test('review state marks ready when threads resolved and approved', function () {
    config([
        'edge.preview_review.require_approval' => true,
        'edge.preview_review.min_approvals' => 1,
    ]);

    $preview = previewSiteForReviewState();
    $user = User::factory()->create();

    EdgePreviewComment::query()->create([
        'organization_id' => $preview->organization_id,
        'site_id' => $preview->id,
        'created_by_user_id' => $user->id,
        'url_path' => '/',
        'body' => 'Done',
        'resolved_at' => now(),
    ]);

    EdgePreviewReviewApproval::query()->create([
        'organization_id' => $preview->organization_id,
        'site_id' => $preview->id,
        'user_id' => $user->id,
    ]);

    $state = app(EdgePreviewReviewState::class)->forPreview($preview);

    expect($state['ready_to_promote'])->toBeTrue()
        ->and($state['open_count'])->toBe(0)
        ->and($state['approval_count'])->toBe(1);
});

function previewSiteForReviewState(): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();

    $parent = Site::factory()->for($org)->for($server)->create([
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => ['source' => ['repo' => 'acme/storefront']],
        ],
    ]);

    return Site::factory()->for($org)->for($server)->create([
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => $parent->id,
                'preview_pr_number' => 7,
                'preview_head_sha' => str_repeat('a', 40),
            ],
        ],
    ]);
}
