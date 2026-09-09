{{-- Who receives one of the org's email defaults.

     Recipients are always organization members. Two of the three emails carry
     secrets — SSH connection details and a plain-text database password — so
     this deliberately does not offer notification channels, which can point at
     Slack, a webhook, or any address someone types. --}}
@php
    $mode = $email_recipient_modes[$emailKey] ?? \App\Models\Organization::EMAIL_RECIPIENT_DEFAULTS[$emailKey];
    $picked = array_map('strval', $email_recipient_user_ids[$emailKey] ?? []);
    $creatorLabel ??= __('the person who triggered it');
@endphp

<div class="mt-2 flex flex-wrap items-center gap-2">
    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Send to') }}</span>

    <select
        wire:model.live="email_recipient_modes.{{ $emailKey }}"
        wire:change="saveEmailRecipients('{{ $emailKey }}')"
        class="h-7 rounded-md border-brand-ink/15 bg-white py-1 pl-2.5 pr-7 text-xs font-semibold text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
        aria-label="{{ __('Recipients') }}"
    >
        <option value="creator">{{ __('Only :who', ['who' => $creatorLabel]) }}</option>
        <option value="admins">{{ __('Owners and admins') }}</option>
        <option value="custom">{{ __('Chosen members') }}</option>
    </select>

    @if ($mode === 'custom')
        <span class="text-2xs text-brand-mist">
            {{ trans_choice('{0} nobody chosen yet|{1} :count member|[2,*] :count members', count($picked), ['count' => count($picked)]) }}
        </span>
    @endif
</div>

@if ($mode === 'custom')
    <div class="mt-2 flex flex-wrap gap-1.5">
        @foreach ($orgMembers as $member)
            @php $checked = in_array((string) $member->id, $picked, true); @endphp
            <label @class([
                'inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition-colors',
                'border-brand-forest bg-brand-sage/10 text-brand-ink' => $checked,
                'border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/30' => ! $checked,
            ])>
                <input
                    type="checkbox"
                    value="{{ $member->id }}"
                    wire:model.live="email_recipient_user_ids.{{ $emailKey }}"
                    wire:change="saveEmailRecipients('{{ $emailKey }}')"
                    class="h-3 w-3 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
                />
                <span class="truncate" title="{{ $member->email }}">{{ $member->name ?: $member->email }}</span>
            </label>
        @endforeach
    </div>
    <p class="mt-1.5 text-2xs leading-relaxed text-brand-mist">
        {{ __(':who is always included. Members who leave the organization stop receiving it.', ['who' => ucfirst($creatorLabel)]) }}
    </p>
@endif
