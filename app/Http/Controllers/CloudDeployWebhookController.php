<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RedeployCloudSiteJob;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Inbound deploy webhook for cloud container sites — used by CI
 * pipelines to trigger a redeploy after publishing a new image.
 *
 * Signed URL is the auth boundary; webhook_secret on the Site
 * row is the URL signer. The body may carry an "image" field
 * that gets passed through to RedeployCloudSiteJob, so a CI run
 * can do "publish image:v1.2.3, then POST {image: ...}" in two
 * steps and dply rolls the new tag.
 *
 * Optional webhook_allowed_ips on the site lets operators pin
 * the source IPs (CI runner ranges); request IP must match if
 * the list is non-empty. Mirrors the pattern already used by
 * the existing site deploy webhook controller.
 */
class CloudDeployWebhookController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['ok' => false, 'reason' => 'invalid_signature'], 403);
        }

        if (! $site->usesContainerRuntime()) {
            return response()->json(['ok' => false, 'reason' => 'not_a_container_site'], 422);
        }

        $allowedIps = $site->webhook_allowed_ips;
        if ($allowedIps !== []) {
            $allowed = array_filter(array_map(static fn (mixed $ip): string => trim((string) $ip), $allowedIps));
            $clientIp = (string) $request->ip();
            if ($allowed !== [] && ! IpUtils::checkIp($clientIp, $allowed)) {
                return response()->json(['ok' => false, 'reason' => 'ip_not_allowed'], 403);
            }
        }

        $image = $request->input('image');
        $newImage = is_string($image) && trim($image) !== '' ? trim($image) : null;

        RedeployCloudSiteJob::dispatch($site->id, $newImage);

        return response()->json([
            'ok' => true,
            'queued' => true,
            'image' => $newImage,
            'site' => $site->id,
        ]);
    }
}
