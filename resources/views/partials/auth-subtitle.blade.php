@php
    $line = match ($variant ?? 'default') {
        'login' => __('Sign in with your email or use a connected provider below.'),
        'register' => __('Create your workspace—no credit card required to get started.'),
        'forgot-password' => __('We will send a secure link to reset your password.'),
        'reset-password' => __('Pick a new password you have not used on other sites.'),
        'verify-email' => __('Open the message we sent and tap the link to verify.'),
        'confirm-password' => __('This extra step protects sensitive changes to your account.'),
        'two-factor' => __('Use your authenticator app or a recovery code.'),
        default => null,
    };
@endphp
@if ($line)
    <p class="mt-1.5 text-sm text-brand-moss leading-relaxed">{{ $line }}</p>
@endif
