<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureReferralCode
{
    /**
     * Store a valid ?referrer= code in session for attribution on sign-up.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isLivewireUpdate = $request->is('livewire*/update');

        if ($isLivewireUpdate) {
            // #region agent log
            @file_put_contents(
                base_path('.cursor/debug-182f08.log'),
                json_encode([
                    'sessionId' => '182f08',
                    'runId' => 'pre-fix',
                    'hypothesisId' => 'S1',
                    'location' => 'app/Http/Middleware/CaptureReferralCode.php:21',
                    'message' => 'Laravel received Livewire update request',
                    'data' => [
                        'path' => $request->path(),
                        'fullUrl' => $request->fullUrl(),
                        'method' => $request->method(),
                        'hasLivewireHeader' => $request->headers->has('X-Livewire'),
                    ],
                    'timestamp' => round(microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND
            );
            // #endregion
        }

        if (! auth()->check()) {
            $code = $request->query('referrer');
            if (is_string($code)) {
                $normalized = trim($code);
                if ($normalized !== '' && strlen($normalized) <= 64
                    && User::query()->where('referral_code', $normalized)->exists()) {
                    session(['referral_code' => $normalized]);
                }
            }
        }

        $response = $next($request);

        if ($isLivewireUpdate) {
            // #region agent log
            @file_put_contents(
                base_path('.cursor/debug-182f08.log'),
                json_encode([
                    'sessionId' => '182f08',
                    'runId' => 'pre-fix',
                    'hypothesisId' => 'S2',
                    'location' => 'app/Http/Middleware/CaptureReferralCode.php:49',
                    'message' => 'Laravel completed Livewire update request',
                    'data' => [
                        'path' => $request->path(),
                        'status' => $response->getStatusCode(),
                        'contentType' => $response->headers->get('content-type'),
                    ],
                    'timestamp' => round(microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND
            );
            // #endregion
        }

        return $response;
    }
}
