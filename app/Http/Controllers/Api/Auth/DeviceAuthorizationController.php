<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\DeviceAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public, unauthenticated entry points for the dply CLI's device-flow
 * login. POST /api/v1/auth/device/start hands back a long device_code
 * + a short user_code; the CLI sends the user to /auth/device to
 * approve, then polls /api/v1/auth/device/poll until authorized.
 */
class DeviceAuthorizationController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $started = DeviceAuthorization::start(
            $request->ip(),
            (string) ($request->userAgent() ?? '')
        );

        /** @var DeviceAuthorization $record */
        $record = $started['record'];
        $deviceCode = $started['device_code'];

        $verificationUri = route('auth.device.show', [], absolute: true);
        $verificationUriComplete = route('auth.device.show', [
            'user_code' => $record->formattedUserCode(),
        ], absolute: true);

        return response()->json([
            'device_code' => $deviceCode,
            'user_code' => $record->formattedUserCode(),
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => $verificationUriComplete,
            'expires_in' => DeviceAuthorization::DEFAULT_TTL_SECONDS,
            'interval' => DeviceAuthorization::DEFAULT_POLL_INTERVAL_SECONDS,
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_code' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        $record = DeviceAuthorization::resolveDeviceCode((string) $data['device_code']);

        if ($record === null) {
            // Don't leak whether the code ever existed — the client
            // can't recover from this anyway; tell it to start over.
            return response()->json(['status' => DeviceAuthorization::STATUS_EXPIRED]);
        }

        if ($record->status === DeviceAuthorization::STATUS_PENDING && $record->isExpired()) {
            $record->update(['status' => DeviceAuthorization::STATUS_EXPIRED]);
        }

        if ($record->status === DeviceAuthorization::STATUS_PENDING) {
            return response()->json(['status' => DeviceAuthorization::STATUS_PENDING]);
        }

        if ($record->status === DeviceAuthorization::STATUS_DENIED) {
            return response()->json(['status' => DeviceAuthorization::STATUS_DENIED]);
        }

        if ($record->status === DeviceAuthorization::STATUS_EXPIRED) {
            return response()->json(['status' => DeviceAuthorization::STATUS_EXPIRED]);
        }

        if ($record->status === DeviceAuthorization::STATUS_AUTHORIZED) {
            // Token can be returned exactly once. Delete the row in
            // the same transaction so a leaked device_code can't fetch
            // it a second time.
            $token = DB::transaction(function () use ($record): ?string {
                $locked = DeviceAuthorization::query()
                    ->whereKey($record->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof DeviceAuthorization) {
                    return null;
                }

                $plaintext = $locked->token_plaintext;
                $locked->delete();

                return $plaintext;
            });

            if ($token === null || $token === '') {
                return response()->json(['status' => DeviceAuthorization::STATUS_EXPIRED]);
            }

            return response()->json([
                'status' => DeviceAuthorization::STATUS_AUTHORIZED,
                'token' => $token,
            ]);
        }

        return response()->json(['status' => DeviceAuthorization::STATUS_EXPIRED]);
    }
}
