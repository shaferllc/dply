@props([
    'organization',
])

@php
    use App\Enums\TrialState;

    $state = $organization?->trialState();
    $billingUrl = $organization ? route('billing.show', $organization) : null;
    $hardPauseAt = $organization?->hardPauseStartsAt();

    // Active-trial countdown: whole days remaining (rounded up).
    $trialDaysLeft = 0;
    if ($state === TrialState::ActiveTrial && $organization?->trial_ends_at) {
        $trialDaysLeft = max(0, (int) ceil(now()->diffInDays($organization->trial_ends_at, false)));
    }
    // Escalate to amber in the final stretch; calm sand before that.
    $trialUrgent = $trialDaysLeft <= 3;

    // Pause states stem from either an expired trial or a canceled
    // subscription — the copy should reflect which. Only resolved for the
    // pause states (it touches the subscriptions relation, which we skip
    // entirely for active/subscribed orgs).
    $lapsedFromSub = in_array($state, [TrialState::ExpiredSoft, TrialState::ExpiredHard], true)
        && ($organization?->lapsedFromSubscription() ?? false);

    // Grace period: canceled but still paid-through. trialState() is still
    // Subscribed here — checked separately so the reminder shows app-wide.
    $onGrace = $state === TrialState::Subscribed
        && ($organization?->onSubscriptionGracePeriod() ?? false);
    $graceEndsAt = $onGrace ? $organization?->subscriptionEndsAt() : null;

    // One band, resolved once. Previously each state hand-rolled its own boxed
    // card with duplicated layout classes; they only ever differed by tone,
    // icon and copy.
    $banner = null;

    if ($onGrace) {
        $banner = [
            'tone' => 'amber',
            'role' => 'status',
            'icon' => 'heroicon-o-clock',
            'title' => $graceEndsAt
                ? __('Your subscription ends :date.', ['date' => $graceEndsAt->toFormattedDateString()])
                : __('Your subscription is set to cancel.'),
            'body' => __('You keep full access until then. Resume anytime to stay on — nothing changes.'),
            'cta' => __('Resume subscription'),
        ];
    } elseif ($state === TrialState::ActiveTrial) {
        $banner = [
            'tone' => $trialUrgent ? 'amber' : 'gold',
            'role' => 'status',
            'icon' => 'heroicon-o-sparkles',
            'title' => $trialDaysLeft <= 0
                ? __('Your trial ends today.')
                : trans_choice('{1} Your trial ends tomorrow.|[2,*] :count days left in your trial.', $trialDaysLeft, ['count' => $trialDaysLeft]),
            'body' => __('Full access while you evaluate. Add a payment method before the trial ends to keep deploys and scheduler runs going.'),
            'cta' => __('Subscribe'),
        ];
    } elseif ($state === TrialState::ExpiredSoft) {
        $softBody = $lapsedFromSub
            ? __('Existing servers and sites keep running. Resume your subscription to restart deploys and scheduler runs.')
            : __('Existing servers and sites keep running. Add a payment method to resume deploys and scheduler runs.');
        if ($hardPauseAt) {
            $softBody .= ' '.__('Agents disconnect on :date if no payment method is added.', ['date' => $hardPauseAt->toFormattedDateString()]);
        }

        $banner = [
            'tone' => 'amber',
            'role' => 'alert',
            'icon' => 'heroicon-o-exclamation-triangle',
            'title' => $lapsedFromSub
                ? __('Deploys are paused — your subscription ended.')
                : __('Deploys are paused — your trial has ended.'),
            'body' => $softBody,
            'cta' => $lapsedFromSub ? __('Resume subscription') : __('Add payment method'),
        ];
    } elseif ($state === TrialState::ExpiredHard) {
        $banner = [
            'tone' => 'red',
            'role' => 'alert',
            'icon' => 'heroicon-o-exclamation-circle',
            'title' => __('This organization is fully paused.'),
            'body' => $lapsedFromSub
                ? __('Agents have been disconnected. Your servers and sites are still running on your provider, but dply is not managing them. Resume your subscription to reconnect.')
                : __('Agents have been disconnected. Your servers and sites are still running on your provider, but dply is not managing them. Add a payment method to reconnect.'),
            'cta' => __('Reactivate'),
        ];
    }

    $tones = [
        'gold' => ['band' => 'border-brand-gold/30 bg-brand-gold/10', 'icon' => 'text-brand-gold', 'title' => 'text-brand-ink', 'body' => 'text-brand-moss'],
        'amber' => ['band' => 'border-amber-300 bg-amber-50', 'icon' => 'text-amber-700', 'title' => 'text-amber-950', 'body' => 'text-amber-900/80'],
        'red' => ['band' => 'border-red-300 bg-red-50', 'icon' => 'text-red-700', 'title' => 'text-red-900', 'body' => 'text-red-900/80'],
    ];
@endphp

@if ($banner)
    @php $tone = $tones[$banner['tone']]; @endphp
    {{-- Full-bleed system band, not a boxed card: this is app-wide state, so it
         reads as chrome sitting under the header rather than as a card competing
         with the page's own content. The border-b hairline is what separates it
         from the page; the inner container keeps the copy on the page's grid. --}}
    <div class="border-b {{ $tone['band'] }}" role="{{ $banner['role'] }}">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-2.5 sm:px-6 lg:px-8">
            <div class="flex min-w-0 flex-1 items-start gap-2.5">
                <x-dynamic-component :component="$banner['icon']" class="mt-0.5 h-4 w-4 shrink-0 {{ $tone['icon'] }}" aria-hidden="true" />
                {{-- Title and body share one paragraph so the band stays a single
                     line at desktop width and wraps naturally when it can't. --}}
                <p class="min-w-0 text-sm leading-snug {{ $tone['body'] }}">
                    <span class="font-semibold {{ $tone['title'] }}">{{ $banner['title'] }}</span>
                    {{ $banner['body'] }}
                </p>
            </div>
            @if ($billingUrl)
                <a
                    href="{{ $billingUrl }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-brand-cream hover:bg-brand-forest"
                >
                    {{ $banner['cta'] }}
                </a>
            @endif
        </div>
    </div>
@endif
