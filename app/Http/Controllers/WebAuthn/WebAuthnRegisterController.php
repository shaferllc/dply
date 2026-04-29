<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laragear\WebAuthn\JsonTransport;

use function response;

class WebAuthnRegisterController
{
    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        if ($request->filled('authenticator_attachment')) {
            $request->validate([
                'authenticator_attachment' => ['required', 'string', 'in:platform,cross-platform'],
            ]);
        }

        $responsable = $request
            ->fastRegistration()
            ->toCreate();

        $attachment = $this->resolveAuthenticatorAttachment($request);

        if ($attachment !== null && $responsable instanceof JsonTransport) {
            $responsable->set('authenticatorSelection.authenticatorAttachment', $attachment);
        }

        return $responsable;
    }

    /**
     * User-provided JSON body overrides config when present.
     * Sending `authenticator_attachment` as an empty string means no attachment hint (browser chooses).
     */
    private function resolveAuthenticatorAttachment(AttestationRequest $request): ?string
    {
        if ($request->has('authenticator_attachment')) {
            $value = $request->input('authenticator_attachment');

            return is_string($value) && in_array($value, ['platform', 'cross-platform'], true)
                ? $value
                : null;
        }

        $fromConfig = config('webauthn.registration.authenticator_attachment');

        return is_string($fromConfig) && in_array($fromConfig, ['platform', 'cross-platform'], true)
            ? $fromConfig
            : null;
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response
    {
        $validated = Validator::make($request->only('alias'), [
            'alias' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $alias = isset($validated['alias']) ? trim((string) $validated['alias']) : '';
        $alias = $alias === '' ? null : $alias;

        $request->save([
            'alias' => $alias,
        ]);

        return response()->noContent();
    }
}
