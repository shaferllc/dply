@php
    $blueprintDescription = __('Save this server\'s reconciled stack as a golden blueprint for the next VM you provision.');
    $previewRole = ucfirst((string) ($previewSnapshot['server_role'] ?? 'application'));

    // The snapshot reads as one context line, the same shape Settings gives its
    // panel heads: what it is, then the qualifiers.
    $previewParts = [$previewSummary, __('Role').': '.$previewRole];
    if ($previewExtras['firewall_rules'] > 0) {
        $previewParts[] = trans_choice(':count firewall rule|:count firewall rules', $previewExtras['firewall_rules'], ['count' => $previewExtras['firewall_rules']]);
    }
    if ($previewExtras['supervisor_programs'] > 0) {
        $previewParts[] = trans_choice(':count daemon|:count daemons', $previewExtras['supervisor_programs'], ['count' => $previewExtras['supervisor_programs']]);
    }
    $previewLine = implode(' · ', $previewParts);

    $inputClass = 'block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    :description="$blueprintDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-document-duplicate"
            :title="__('Blueprint')"
            :note="$blueprintDescription"
            :count="$orgBlueprints->count().' / '.$maxBlueprints"
            class="border-b border-brand-ink/10"
        />

        <x-workspace-panel-head
            dense
            icon="heroicon-o-camera"
            :title="__('Snapshot preview')"
            :note="$previewLine"
            class="border-b border-brand-ink/10"
        />

        <form wire:submit.prevent="saveBlueprint" class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 basis-64">
                    <x-input-label for="blueprint_name" :value="__('Blueprint name')" />
                    <input
                        wire:model="blueprint_name"
                        id="blueprint_name"
                        type="text"
                        required
                        class="{{ $inputClass }} mt-1 max-w-md"
                    >
                </div>
                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveBlueprint">{{ __('Save blueprint') }}</span>
                    <span wire:loading wire:target="saveBlueprint">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
            <x-input-error :messages="$errors->get('blueprint_name')" class="mt-1" />
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Shown in the create-server wizard. :count of :max blueprints saved for this organization.', ['count' => $orgBlueprints->count(), 'max' => $maxBlueprints]) }}
            </p>
        </form>

        @if ($orgBlueprints->isNotEmpty())
            <x-workspace-panel-head
                dense
                icon="heroicon-o-rectangle-stack"
                :title="__('Organization blueprints')"
                :note="__('Pick any of these when provisioning a new VM in Step 3 — What it runs.')"
                class="border-b border-brand-ink/10"
            />

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                    <thead class="bg-brand-sand/30 text-brand-moss">
                        <tr>
                            <th class="px-5 py-1.5 font-medium sm:px-6">{{ __('Name') }}</th>
                            <th class="px-3 py-1.5 font-medium">{{ __('Stack') }}</th>
                            <th class="px-3 py-1.5 font-medium">{{ __('Source') }}</th>
                            <th class="px-3 py-1.5 font-medium">{{ __('Updated') }}</th>
                            <th class="px-5 py-1.5 text-right font-medium sm:px-6">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                        @foreach ($orgBlueprints as $blueprint)
                            <tr class="hover:bg-brand-sand/20">
                                <td class="whitespace-nowrap px-5 py-2 sm:px-6">
                                    <button
                                        type="button"
                                        wire:click="openDetailModal('{{ $blueprint->id }}')"
                                        class="rounded font-semibold text-brand-ink underline decoration-brand-ink/25 underline-offset-2 transition-colors hover:text-brand-forest hover:decoration-brand-forest/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                                        title="{{ __('View what this blueprint captured') }}"
                                    >
                                        {{ $blueprint->name }}
                                    </button>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-brand-moss">{{ $summary->tagline($blueprint->snapshot) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-brand-moss">{{ $blueprint->sourceServer?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-brand-moss" title="{{ $blueprint->updated_at->format('Y-m-d H:i:s') }}">{{ $blueprint->updated_at->diffForHumans() }}</td>
                                <td class="whitespace-nowrap px-5 py-2 text-right sm:px-6">
                                    <button
                                        type="button"
                                        wire:click="openDeleteModal('{{ $blueprint->id }}')"
                                        class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-800 transition-colors hover:bg-rose-100"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @include('livewire.servers.partials.blueprint-details-modal', [
        'blueprint' => $this->viewingBlueprint,
        'summary' => $summary,
    ])

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
