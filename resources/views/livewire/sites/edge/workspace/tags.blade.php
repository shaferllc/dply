<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Tags') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Load third-party scripts (analytics, pixels) from the Edge without shipping them in your repo.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable tag manager') }}</span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="consent_required" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span>
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Consent helper') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Exposes `window.__dplyTags.consent` for your CMP to flip via localStorage `dply_tag_consent`.') }}</span>
                </span>
            </label>

            @foreach ($tools as $i => $tool)
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3 sm:grid-cols-3" wire:key="tag-{{ $i }}">
                    <div>
                        <x-input-label :value="__('Name')" />
                        <x-text-input wire:model="tools.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Script URL (https)')" />
                        <x-text-input wire:model="tools.{{ $i }}.src" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://…" @disabled(! $managedDelivery) />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-brand-ink sm:col-span-2">
                        <input type="checkbox" wire:model="tools.{{ $i }}.async" class="rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                        {{ __('Async') }}
                    </label>
                    @if (count($tools) > 1)
                        <button type="button" wire:click="removeTool({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addTool" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add tag') }}</button>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
