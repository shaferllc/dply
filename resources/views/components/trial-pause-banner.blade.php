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
@endphp

@if ($state === TrialState::ActiveTrial)
    <div class="rounded-xl border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-3 {{ $trialUrgent ? 'border-amber-300 bg-amber-50 text-amber-950' : 'border-brand-gold/30 bg-brand-gold/10 text-brand-ink' }}" role="status">
        <div>
            <p class="font-semibold">
                @if ($trialDaysLeft <= 0)
                    {{ __('Your trial ends today.') }}
                @else
                    {{ trans_choice('{1} Your trial ends tomorrow.|[2,*] :count days left in your trial.', $trialDaysLeft, ['count' => $trialDaysLeft]) }}
                @endif
            </p>
            <p class="mt-0.5 {{ $trialUrgent ? 'text-amber-900/80' : 'text-brand-moss' }}">
                {{ __('Full access while you evaluate. Add a payment method before the trial ends to keep deploys and scheduler runs going.') }}
            </p>
        </div>
        @if ($billingUrl)
            <a href="{{ $billingUrl }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold whitespace-nowrap {{ $trialUrgent ? 'bg-brand-ink text-brand-cream hover:bg-brand-forest' : 'border-2 border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40' }}">
                {{ __('Subscribe') }}
            </a>
        @endif
    </div>
@elseif ($state === TrialState::ExpiredSoft)
    <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 flex flex-wrap items-center justify-between gap-3" role="alert">
        <div>
            <p class="font-semibold">{{ __('Deploys are paused — your trial has ended.') }}</p>
            <p class="mt-0.5 text-amber-900/80">
                {{ __('Existing servers and sites keep running. Add a payment method to resume deploys and scheduler runs.') }}
                @if ($hardPauseAt)
                    {{ __('Agents disconnect on :date if no payment method is added.', ['date' => $hardPauseAt->toFormattedDateString()]) }}
                @endif
            </p>
        </div>
        @if ($billingUrl)
            <a href="{{ $billingUrl }}" wire:navigate class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                {{ __('Add payment method') }}
            </a>
        @endif
    </div>
@elseif ($state === TrialState::ExpiredHard)
    <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 flex flex-wrap items-center justify-between gap-3" role="alert">
        <div>
            <p class="font-semibold">{{ __('This organization is fully paused.') }}</p>
            <p class="mt-0.5 text-red-900/80">
                {{ __('Agents have been disconnected. Your servers and sites are still running on your provider, but dply is not managing them. Add a payment method to reconnect.') }}
            </p>
        </div>
        @if ($billingUrl)
            <a href="{{ $billingUrl }}" wire:navigate class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                {{ __('Reactivate') }}
            </a>
        @endif
    </div>
@endif
