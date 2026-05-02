<?php

namespace App\Http\Controllers;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WebhookDeliveryLog;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteWebhookSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteDeployWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        SiteWebhookSignatureValidator $validator,
        SiteDeploySyncCoordinator $syncCoordinator,
    ): JsonResponse|Response {
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent(204);
        }

        $result = $validator->validateForWebhook($request, $site);

        $providerEvent = $request->header('X-GitHub-Event')
            ?? $request->header('X-Gitlab-Event')
            ?? $request->header('X-Event-Key');
        $providerDelivery = $request->header('X-GitHub-Delivery')
            ?? $request->header('X-Request-UUID');

        WebhookDeliveryLog::query()->create([
            'site_id' => $site->id,
            'request_ip' => $request->ip(),
            'http_status' => $result['status'],
            'outcome' => $result['outcome'],
            'detail' => $result['detail'],
            'provider_event' => is_string($providerEvent) ? substr($providerEvent, 0, 64) : null,
            'provider_delivery_id' => is_string($providerDelivery) ? substr($providerDelivery, 0, 128) : null,
        ]);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        if (! $this->shouldQueueDeployFromProviderPayload($request, $site, $result)) {
            return response()->json(['message' => 'Ignored.'], 200);
        }

        $site->loadMissing('organization');
        if ($site->organization) {
            audit_log($site->organization, null, 'site.deploy.webhook_queued', $site, null, [
                'site' => $site->name,
            ]);
        }

        RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_WEBHOOK);
        $syncCoordinator->queuePeerDeploysFromWebhook($site);

        return response()->json(['message' => $result['message']], 202);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldQueueDeployFromProviderPayload(Request $request, Site $site, array $result): bool
    {
        $auth = $result['auth_mode'] ?? 'dply';
        if ($auth === 'dply') {
            return true;
        }

        $payload = json_decode($request->getContent(), true);
        $branch = trim((string) ($site->git_branch ?: 'main'));

        if ($auth === 'github') {
            $ev = (string) $request->header('X-GitHub-Event', '');
            if ($ev === 'ping') {
                return false;
            }
            if ($ev === 'push' && is_array($payload)) {
                $ref = (string) ($payload['ref'] ?? '');

                return $ref === 'refs/heads/'.$branch;
            }

            return false;
        }

        if ($auth === 'gitlab') {
            $ev = strtolower((string) $request->header('X-Gitlab-Event', ''));
            if ($ev === 'push hook' && is_array($payload)) {
                $ref = (string) ($payload['ref'] ?? '');

                return $ref === 'refs/heads/'.$branch;
            }

            return false;
        }

        if ($auth === 'bitbucket') {
            $ev = (string) $request->header('X-Event-Key', '');
            if ($ev !== 'repo:push') {
                return false;
            }
            if (! is_array($payload)) {
                return true;
            }
            $changes = $payload['push']['changes'] ?? [];
            if (! is_array($changes) || $changes === []) {
                return true;
            }
            $new = $changes[0]['new'] ?? null;
            if (is_array($new) && isset($new['name'])) {
                return (string) $new['name'] === $branch;
            }

            return true;
        }

        return true;
    }
}
