@php
    $statusPill = function (\App\Models\BetaInvitation $i): array {
        if ($i->isRevoked()) {
            return ['bg-zinc-100 text-zinc-600', __('Revoked')];
        }
        if ($i->isRedeemed()) {
            return ['bg-emerald-50 text-emerald-800', __('Redeemed')];
        }
        if ($i->isExpired()) {
            return ['bg-amber-50 text-amber-800', __('Expired')];
        }
        return ['bg-sky-50 text-sky-800', __('Pending')];
    };
@endphp

<div>
    <x-page-header
        :title="__('Beta invites')"
        :description="__('Issue closed-beta invites by email. Invitees get the platform free (BYO servers) plus one free dply-managed server. Admin-only — no peer invites.')"
        flush
        compact
    />

    {{-- Issue invites --}}
    <section class="mb-8 dply-card-compact">
        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Send invites') }}</h2>
        <p class="mt-1 text-xs text-brand-moss">{{ __('One address or many — separate with commas, spaces, or new lines. Already-registered addresses are skipped.') }}</p>
        <form wire:submit="sendInvites" class="mt-3 space-y-3">
            <textarea wire:model="emails" rows="3"
                      placeholder="alex@example.com, sam@example.com"
                      class="block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-gold focus:ring-brand-gold/40"></textarea>
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" wire:target="sendInvites"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                    <span wire:loading.remove wire:target="sendInvites">{{ __('Send invites') }}</span>
                    <span wire:loading wire:target="sendInvites">{{ __('Sending…') }}</span>
                </button>
            </div>
        </form>
    </section>

    {{-- Waitlist funnel --}}
    <section class="mb-8 dply-card-compact">
        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Waitlist') }}</h2>
        <p class="mt-1 text-xs text-brand-moss">{{ __('Coming-soon signups not yet invited. Pick who to let in.') }}</p>
        @if ($waitlist->isEmpty())
            <p class="mt-4 text-sm text-brand-mist">{{ __('No un-invited waitlist signups.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-brand-ink/5">
                @foreach ($waitlist as $signup)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-brand-ink">{{ $signup->email }}</p>
                            <p class="text-[11px] text-brand-mist">{{ __('Joined :when', ['when' => $signup->created_at?->diffForHumans()]) }}{{ $signup->source ? ' · '.$signup->source : '' }}</p>
                        </div>
                        <button type="button" wire:click="inviteFromWaitlist('{{ $signup->email }}')" wire:loading.attr="disabled"
                                class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Invite') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Issued invites --}}
    <section class="dply-card-compact">
        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Issued invites') }}</h2>
        @if ($invitations->isEmpty())
            <p class="mt-4 text-sm text-brand-mist">{{ __('No invites issued yet.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                            <th class="py-2 pr-4">{{ __('Email') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 pr-4">{{ __('Source') }}</th>
                            <th class="py-2 pr-4">{{ __('Expires') }}</th>
                            <th class="py-2 pr-4 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($invitations as $invitation)
                            @php([$pillClass, $pillLabel] = $statusPill($invitation))
                            <tr>
                                <td class="py-2 pr-4 text-brand-ink">{{ $invitation->email }}</td>
                                <td class="py-2 pr-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $pillClass }}">{{ $pillLabel }}</span>
                                </td>
                                <td class="py-2 pr-4 text-brand-moss">{{ $invitation->source }}</td>
                                <td class="py-2 pr-4 text-brand-moss">{{ $invitation->expires_at?->diffForHumans() }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if ($invitation->isRedeemable())
                                        <button type="button" wire:click="resend('{{ $invitation->id }}')"
                                                class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Resend') }}</button>
                                        <button type="button" wire:click="revoke('{{ $invitation->id }}')"
                                                wire:confirm="{{ __('Revoke this invite? It can no longer be redeemed.') }}"
                                                class="ml-3 text-xs font-semibold text-red-700 hover:underline">{{ __('Revoke') }}</button>
                                    @else
                                        <span class="text-xs text-brand-mist">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
