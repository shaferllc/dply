@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    :description="__('Save this server\'s reconciled stack as a golden blueprint for the next VM you provision.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Blueprints capture the installed stack (webserver, PHP, database, cache, runtimes) plus server-level firewall and daemon baselines. Applying a blueprint in the create wizard pre-fills Step 3 — firewall and daemon templates are reference-only in v1.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Snapshot preview') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $previewSummary }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Role') }}: <span class="font-semibold text-brand-ink">{{ ucfirst((string) ($previewSnapshot['server_role'] ?? 'application')) }}</span>
                        @if ($previewExtras['firewall_rules'] > 0)
                            · {{ trans_choice(':count firewall rule|:count firewall rules', $previewExtras['firewall_rules'], ['count' => $previewExtras['firewall_rules']]) }}
                        @endif
                        @if ($previewExtras['supervisor_programs'] > 0)
                            · {{ trans_choice(':count daemon|:count daemons', $previewExtras['supervisor_programs'], ['count' => $previewExtras['supervisor_programs']]) }}
                        @endif
                    </p>
                </div>
            </div>

            <form wire:submit.prevent="saveBlueprint" class="space-y-4 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="blueprint_name" :value="__('Blueprint name')" />
                    <x-text-input
                        wire:model="blueprint_name"
                        id="blueprint_name"
                        type="text"
                        class="mt-1 block w-full max-w-md"
                        required
                    />
                    <x-input-error :messages="$errors->get('blueprint_name')" class="mt-1" />
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Shown in the create-server wizard. :count of :max blueprints saved for this organization.', ['count' => $orgBlueprints->count(), 'max' => $maxBlueprints]) }}
                    </p>
                </div>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveBlueprint">{{ __('Save blueprint') }}</span>
                    <span wire:loading wire:target="saveBlueprint">{{ __('Saving…') }}</span>
                </x-primary-button>
            </form>
        </section>

        @if ($orgBlueprints->isNotEmpty())
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization blueprints') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Pick any of these when provisioning a new VM in Step 3 — What it runs.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($orgBlueprints as $blueprint)
                        @php
                            $tagline = $summary->tagline($blueprint->snapshot);
                            $extras = $summary->extras($blueprint->snapshot);
                        @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-ink">{{ $blueprint->name }}</p>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ $tagline }}</p>
                                <p class="mt-1 text-xs text-brand-mist">
                                    @if ($blueprint->sourceServer)
                                        {{ __('From :server', ['server' => $blueprint->sourceServer->name]) }} ·
                                    @endif
                                    {{ $blueprint->updated_at->diffForHumans() }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="openDeleteModal('{{ $blueprint->id }}')"
                                class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100"
                            >
                                {{ __('Delete') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <x-modal name="delete-blueprint-confirmation" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Delete blueprint?') }}</h2>
            <p class="mt-2 text-sm text-brand-moss">{{ __('This removes the saved snapshot from your organization. Existing servers are not changed.') }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeDeleteModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteBlueprint">{{ __('Delete blueprint') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
</x-server-workspace-layout>
