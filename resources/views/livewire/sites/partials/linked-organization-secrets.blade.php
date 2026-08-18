@php
    $linkedSecretRows = method_exists($this, 'linkedOrganizationSecretRows')
        ? $this->linkedOrganizationSecretRows()
        : [];
    $canLinkSecrets = method_exists($this, 'openLinkOrganizationSecretModal');
    $secretsCard = $secretsCard ?? 'border-b border-brand-ink/10';
@endphp

@if ($canLinkSecrets)
    <section class="{{ $secretsCard }}">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-2.5 sm:px-6">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Linked secrets') }}</h2>
                <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">
                    {{ trans_choice('{0} none|{1} :count linked|[2,*] :count linked', count($linkedSecretRows), ['count' => count($linkedSecretRows)]) }}
                </span>
            </div>
            @can('update', $site)
                <button
                    type="button"
                    wire:click="openLinkOrganizationSecretModal"
                    x-on:click="$dispatch('open-modal', 'link-organization-secret-modal')"
                    class="dply-btn dply-btn-sm dply-btn-outline"
                >
                    {{ __('Link secrets') }}
                </button>
            @endcan
        </div>

        @if ($linkedSecretRows === [])
            <p class="px-5 py-4 text-sm text-brand-moss sm:px-6">{{ __('No org secrets linked. Link one from the organization vault — it applies on the next deploy.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($linkedSecretRows as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5 sm:px-6" wire:key="linked-secret-{{ $row['id'] }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm text-brand-ink">{{ $row['key'] }}</span>
                                @if ($row['overrides_site'])
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">{{ __('Override') }}</span>
                                @endif
                                @if ($row['binding_owned'])
                                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('Binding owns this key') }}</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $row['notes'] ?: __('encrypted · write-only') }}</p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                class="text-xs font-semibold text-rose-700 hover:text-rose-900"
                                wire:click="openConfirmActionModal('unlinkOrganizationSecret', @js([$row['id']]), @js(__('Unlink secret')), @js(__('Unlink :key from this site? The key drops on the next deploy. The org secret is kept.', ['key' => $row['key']])), @js(__('Unlink')), true)"
                            >
                                {{ __('Unlink') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @include('livewire.sites.partials.link-organization-secret-modal')
@endif
