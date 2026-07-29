<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;

/**
 * Connect GitHub webhooks on production Edge sites so PR previews
 * automatically post Check Runs + summary comments (C1/C2).
 */
class EdgeGithubPreviewEnsurer
{
    public function __construct(
        private readonly EdgeGithubWebhookProvisioner $provisioner,
        private readonly GitIdentityResolver $resolver,
    ) {}

    /**
     * @return list<array{slug: string, connected: bool, message: string}>
     */
    public function ensureAllProductionSites(): array
    {
        $results = [];

        Site::query()
            ->whereNotNull('edge_backend')
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->orderBy('id')
            ->each(function (Site $site) use (&$results): void {
                if (! $site->usesEdgeRuntime() || $site->isEdgePreview()) {
                    return;
                }

                $repo = trim((string) ($site->edgeMeta()['source']['repo'] ?? ''));
                if ($repo === '' || ! str_contains($repo, '/')) {
                    $results[] = [
                        'slug' => (string) $site->slug,
                        'connected' => false,
                        'message' => 'No git repository configured.',
                    ];

                    return;
                }

                if ($this->provisioner->isConnected($site)) {
                    $results[] = [
                        'slug' => (string) $site->slug,
                        'connected' => true,
                        'message' => 'GitHub webhook already connected.',
                    ];

                    return;
                }

                $account = $this->resolveGithubAccount($site);
                if ($account === null) {
                    $results[] = [
                        'slug' => (string) $site->slug,
                        'connected' => false,
                        'message' => 'Link GitHub under Profile → Source control, then enable auto-deploy in Build settings.',
                    ];

                    return;
                }

                $result = $this->provisioner->enable($site->fresh(), $account);
                $results[] = [
                    'slug' => (string) $site->slug,
                    'connected' => (bool) ($result['ok'] ?? false),
                    'message' => (string) ($result['message'] ?? ''),
                ];
            });

        return $results;
    }

    private function resolveGithubAccount(Site $site): ?GitIdentity
    {
        $user = $site->user;
        if ($user === null) {
            return null;
        }

        $webhook = is_array($site->edgeMeta()['webhook'] ?? null) ? $site->edgeMeta()['webhook'] : null;
        $accountId = is_array($webhook) ? trim((string) ($webhook['account_id'] ?? '')) : '';
        if ($accountId !== '') {
            return $this->resolver->forId($user, $accountId);
        }

        foreach ($this->resolver->allForUser($user) as $account) {
            if ($account->provider() === 'github' && $account->accessToken() !== '') {
                return $account;
            }
        }

        return null;
    }
}
