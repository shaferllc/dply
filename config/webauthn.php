<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | We will use your application information to inform the device who is the
    | relying party. While only the name is enough, you can further set the
    | a custom domain as ID and even an icon image data encoded as BASE64.
    |
    */

    'relying_party' => [
        'name' => env('WEBAUTHN_NAME', config('app.name')),
        'id' => env('WEBAUTHN_ID') ?: parse_url((string) config('app.url'), PHP_URL_HOST),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origins
    |--------------------------------------------------------------------------
    |
    | By default, only your application domain is used as a valid origin for
    | all ceremonies. If you are using your app as a backend for an app or
    | UI you may set additional origins to check against the ceremonies.
    |
    | For multiple origins, separate them using comma, like `foo,bar`.
    */

    'origins' => env('WEBAUTHN_ORIGINS'),

    /*
    |--------------------------------------------------------------------------
    | Challenge configuration
    |--------------------------------------------------------------------------
    |
    | When making challenges your application needs to push at least 16 bytes
    | of randomness. Since we need to later check them, we'll also store the
    | bytes for a small amount of time inside this current request session.
    |
    | @see https://www.w3.org/TR/webauthn-2/#sctn-cryptographic-challenges
    |
    */

    'challenge' => [
        'bytes' => 16,
        'timeout' => 60,
        'key' => '_webauthn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration authenticator attachment (optional)
    |--------------------------------------------------------------------------
    |
    | When null, the browser chooses among platform (Touch ID, Windows Hello)
    | and cross-platform authenticators (security keys, password managers).
    | Users can pick the passkey type on the Security page; that choice overrides
    | this default. Set "platform" here to prefer the OS UI when the client
    | omits a choice (e.g. older clients).
    |
    | @see https://www.w3.org/TR/webauthn-2/#enumdef-authenticatorattachment
    |
    */

    'registration' => [
        'authenticator_attachment' => env('WEBAUTHN_AUTHENTICATOR_ATTACHMENT'),
    ],
];
