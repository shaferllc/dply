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

        return $next($request);
    }
}
