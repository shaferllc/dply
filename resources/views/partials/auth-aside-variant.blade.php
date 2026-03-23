@php
    $authAsideVariant = match (request()->route()?->getName()) {
        'login' => 'login',
        'register' => 'register',
        'password.request' => 'forgot-password',
        'password.reset' => 'reset-password',
        'two-factor.login' => 'two-factor',
        'verification.notice' => 'verify-email',
        'password.confirm' => 'confirm-password',
        default => 'default',
    };
@endphp
