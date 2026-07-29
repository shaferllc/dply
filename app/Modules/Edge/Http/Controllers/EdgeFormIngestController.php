<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Receives form POSTs forwarded by the Edge Worker (HMAC-authenticated).
 */
final class EdgeFormIngestController
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (($site->edge_backend ?? '') !== 'dply_edge') {
            return response()->json(['ok' => false, 'error' => 'not_edge'], 404);
        }

        $secret = (string) config('edge.log_ingest.key', '');
        if ($secret === '') {
            return response()->json(['ok' => false, 'error' => 'ingest_unconfigured'], 503);
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('X-Dply-Edge-Form-Signature', '');
        $expected = hash_hmac('sha256', $raw, $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?? [];
        $validator = Validator::make($payload, [
            'to_email' => ['required', 'email'],
            'path' => ['required', 'string', 'max:255'],
            'fields' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 422);
        }

        $to = (string) $payload['to_email'];
        $path = (string) $payload['path'];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $siteName = (string) $site->name;

        $lines = ["New form submission on {$siteName}", "Path: {$path}", ''];
        foreach ($fields as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $lines[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value));
        }
        $body = implode("\n", $lines);

        try {
            Mail::raw($body, function ($message) use ($to, $siteName, $path): void {
                $message->to($to)->subject("[dply Edge] Form: {$siteName} ({$path})");
            });
        } catch (\Throwable $e) {
            Log::warning('edge.form.mail_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'error' => 'mail_failed'], 502);
        }

        return response()->json(['ok' => true]);
    }
}
