<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Cloud\Namecheap\NamecheapDnsService;
use App\Support\TestingDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Control-plane ACME DNS-01 helper for Namecheap. Wildcard certbot hooks
 * run on the guest VM, but Namecheap only accepts API calls from the
 * allowlisted ClientIp — so the VM posts here and we write the TXT.
 */
class AcmeDnsHookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $this->hookSecret();
        $signature = strtolower(trim((string) $request->header('X-Dply-Signature', '')));
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $action = strtolower(trim((string) $request->input('action', '')));
        $zone = strtolower(trim((string) $request->input('zone', '')));
        $name = strtolower(trim((string) $request->input('name', '_acme-challenge')));
        $value = (string) $request->input('value', '');

        if (! in_array($action, ['set', 'clear'], true) || $zone === '' || TestingDomains::zoneForHost($zone) !== $zone) {
            return response()->json(['message' => 'Invalid ACME DNS payload.'], 422);
        }

        if ($name === '' || str_contains($name, '.')) {
            $name = '_acme-challenge';
        }

        try {
            $dns = NamecheapDnsService::fromAppConfig();
            if ($action === 'set') {
                if ($value === '') {
                    return response()->json(['message' => 'Missing challenge value.'], 422);
                }
                $dns->upsertTxtRecord($zone, $name, $value);
            } else {
                $dns->deleteHost($zone, $name, 'TXT');
            }
        } catch (\Throwable $e) {
            Log::warning('acme.dns.hook_failed', [
                'action' => $action,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    public static function hookSecret(): string
    {
        $explicit = trim((string) config('testing_domains.acme_hook_secret', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (string) config('app.key', '');
    }

    public static function hookUrl(): string
    {
        $base = rtrim((string) (config('dply.public_app_url') ?: config('app.url')), '/');

        return $base.'/hooks/acme-dns';
    }
}
