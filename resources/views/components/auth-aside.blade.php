@props([
    'variant' => 'default',
])

@php
    $blocks = match ($variant) {
        'login' => [
            'title' => 'Welcome back',
            'lead' => 'Pick up where you left off—servers, sites, and deploys stay scoped to your organization.',
            'items' => [
                ['icon' => 'server', 'title' => 'One console', 'text' => 'Dashboard, SSH commands, and provider links without juggling tokens.'],
                ['icon' => 'shield', 'title' => 'Org-scoped access', 'text' => 'Credentials and servers belong to the team—no shared pastebins of API keys.'],
                ['icon' => 'spark', 'title' => 'Ship faster', 'text' => 'Git deploys, SSL, and workers configured next to each site.'],
            ],
        ],
        'register' => [
            'title' => 'Start in minutes',
            'lead' => 'Create an account, add an organization, and connect your first cloud or SSH host.',
            'items' => [
                ['icon' => 'org', 'title' => 'Organizations first', 'text' => 'Billing and resources group naturally for teams and side projects.'],
                ['icon' => 'cloud', 'title' => 'Many clouds', 'text' => 'DigitalOcean, Hetzner, AWS, Fly.io, and more—or bring any server over SSH.'],
                ['icon' => 'key', 'title' => 'Vaulted secrets', 'text' => 'API tokens and deploy keys stay encrypted; teammates use the app, not raw secrets.'],
            ],
        ],
        'forgot-password' => [
            'title' => 'Account recovery',
            'lead' => 'We will email a single-use link. It expires for your security.',
            'items' => [
                ['icon' => 'mail', 'title' => 'Check spam', 'text' => 'If nothing arrives in a minute, peek at junk or promotions folders.'],
                ['icon' => 'lock', 'title' => 'Strong passwords', 'text' => 'After reset, consider a password manager and optional 2FA in profile.'],
                ['icon' => 'user', 'title' => 'Same email', 'text' => 'Use the address you registered with—aliases must match exactly.'],
            ],
        ],
        'reset-password' => [
            'title' => 'Choose a new password',
            'lead' => 'You are one step away from signing in again with fresh credentials.',
            'items' => [
                ['icon' => 'lock', 'title' => 'Unique & long', 'text' => 'Avoid reuse from other sites; length beats complexity.'],
                ['icon' => 'shield', 'title' => '2FA optional', 'text' => 'Add an authenticator from profile settings after you log in.'],
                ['icon' => 'spark', 'title' => 'Link expires', 'text' => 'If this page errors, request a new reset email from the login screen.'],
            ],
        ],
        'verify-email' => [
            'title' => 'Almost there',
            'lead' => 'Verified email keeps invitations, billing notices, and security alerts reliable.',
            'items' => [
                ['icon' => 'mail', 'title' => 'Inbox required', 'text' => 'Servers, orgs, and sensitive actions need a confirmed address.'],
                ['icon' => 'spark', 'title' => 'Resend anytime', 'text' => 'Use the button below if the link did not land the first time.'],
                ['icon' => 'shield', 'title' => 'Wrong account?', 'text' => 'Log out and register with the email you want to keep.'],
            ],
        ],
        'confirm-password' => [
            'title' => 'Sensitive action',
            'lead' => 'Re-entering your password prevents stray clicks from changing critical settings.',
            'items' => [
                ['icon' => 'shield', 'title' => 'Short detour', 'text' => 'You will return to what you were doing right after.'],
                ['icon' => 'lock', 'title' => 'Session based', 'text' => 'We may ask again after a while or on a new device.'],
                ['icon' => 'user', 'title' => 'Forgot it?', 'text' => 'Log out and use “Forgot password” from the login page.'],
            ],
        ],
        'two-factor' => [
            'title' => 'Second factor',
            'lead' => 'Enter the 6-digit code from your authenticator app, or a one-time recovery code.',
            'items' => [
                ['icon' => 'lock', 'title' => 'Time-based codes', 'text' => 'They refresh every 30 seconds—wait for the new one if it just rolled.'],
                ['icon' => 'spark', 'title' => 'Recovery codes', 'text' => 'Each backup code works once; store them somewhere safe offline.'],
                ['icon' => 'shield', 'title' => 'Lost device?', 'text' => 'Use a recovery code, then add 2FA again from profile settings.'],
            ],
        ],
        default => [
            'title' => config('app.name'),
            'lead' => 'Infrastructure operations for teams—servers, sites, and secrets in one place.',
            'items' => [
                ['icon' => 'server', 'title' => 'Control plane', 'text' => 'Provision, configure, and run commands from the browser.'],
                ['icon' => 'cloud', 'title' => 'Your clouds', 'text' => 'Link providers or SSH—everything stays under your organization.'],
                ['icon' => 'shield', 'title' => 'Built for teams', 'text' => 'Invites, audit visibility, and per-org billing as you scale.'],
            ],
        ],
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-brand-ink/10 bg-gradient-to-b from-brand-forest/5 to-white/60 p-8 lg:p-10']) }}>
    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">{{ config('app.name') }}</p>
    <h2 class="mt-2 text-xl font-bold tracking-tight text-brand-ink leading-snug sm:text-2xl">{{ $blocks['title'] }}</h2>
    <p class="mt-3 text-sm text-brand-moss leading-relaxed">{{ $blocks['lead'] }}</p>
    <ul class="mt-8 space-y-6">
        @foreach ($blocks['items'] as $item)
            <li class="flex gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-gold/15 text-brand-forest" aria-hidden="true">
                    @switch($item['icon'])
                        @case('server')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                            @break
                        @case('shield')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            @break
                        @case('spark')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            @break
                        @case('org')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            @break
                        @case('cloud')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                            @break
                        @case('key')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            @break
                        @case('mail')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            @break
                        @case('lock')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            @break
                        @case('user')
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            @break
                        @default
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @endswitch
                </span>
                <div>
                    <p class="font-semibold text-brand-ink text-sm">{{ $item['title'] }}</p>
                    <p class="mt-1 text-sm text-brand-moss leading-relaxed">{{ $item['text'] }}</p>
                </div>
            </li>
        @endforeach
    </ul>
    <p class="mt-8 pt-6 border-t border-brand-ink/10 text-xs text-brand-moss">
        Questions? See <a href="{{ route('features') }}" class="font-medium text-brand-sage hover:text-brand-forest underline decoration-brand-sage/40 underline-offset-2">features</a>
        or <a href="{{ route('pricing') }}" class="font-medium text-brand-sage hover:text-brand-forest underline decoration-brand-sage/40 underline-offset-2">pricing</a>.
    </p>
</div>
