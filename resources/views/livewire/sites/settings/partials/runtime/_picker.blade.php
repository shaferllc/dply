{{-- VM sites only. The Runtime tab also renders for Docker / Kubernetes /
     serverless workspaces, whose runtime comes from an image or platform
     config — a picker writing start_command/internal_port would be meaningless
     there, and SetSiteRuntime only knows how to re-apply a VM vhost. --}}
@if ($site->runtimeTargetMode() === 'vm')
@php
    // The runtime switcher. Included from BOTH the Runtime Overview tab and the
    // generic language tab, deliberately: it first lived only in the generic
    // tab, which renders only when the runtime is already node/python/…, so a
    // PHP site on a PHP-less host — the exact case this exists for — had no way
    // to reach it. Overview is always present, so the switcher always is too.
    $pickerCatalog = (array) config('server_manage.mise_runtimes', []);
    $pickerCurrent = (string) ($site->runtime ?? '');
    $pickerEntry = $pickerCatalog[$this->runtime_choice] ?? [];
    $pickerPlaceholder = (string) ($pickerEntry['placeholder'] ?? ($this->runtime_choice === 'php' ? '8.3' : ''));
    $pickerHint = (string) ($pickerEntry['hint'] ?? '');

    $pickerOptions = $this->siteRuntimeOptions();
    $pickerInstalled = array_values(array_filter($pickerOptions, fn (array $o) => $o['installed']));
    $pickerMissing = array_values(array_filter($pickerOptions, fn (array $o) => ! $o['installed']));

    // Follows the SELECTED runtime, not the saved one — the select is
    // wire:model.live precisely so picking Node on a PHP site reveals the
    // fields that switch requires. Keying this off $site->runtime would hide
    // them and make the switch impossible to complete.
    $pickerProxied = in_array($this->runtime_choice, \App\Actions\Sites\SetSiteRuntime::proxiedRuntimes(), true);
    $pickerBody = 'px-5 py-3 sm:px-6';
    $pickerHelp = 'mt-1 text-xs text-brand-moss';
@endphp

<form wire:submit="switchSiteRuntime" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-cube-transparent"
        :title="__('Runtime')"
        :note="__('What this site runs on. Changing it rewrites the web server config so the box matches the record.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="switchSiteRuntime">
                <span wire:loading.remove wire:target="switchSiteRuntime">{{ __('Save') }}</span>
                <span wire:loading wire:target="switchSiteRuntime">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $pickerBody }} space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="runtime_choice" :value="__('Runtime')" class="!text-xs" />
                <select id="runtime_choice" wire:model.live="runtime_choice"
                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-forest focus:ring-brand-forest">
                    <optgroup label="{{ __('Installed on this server') }}">
                        @foreach ($pickerInstalled as $option)
                            <option value="{{ $option['key'] }}">
                                {{ $option['label'] }}@if ($option['version']) · {{ $option['version'] }}@endif
                            </option>
                        @endforeach
                    </optgroup>
                    @if ($pickerMissing !== [])
                        <optgroup label="{{ __('Not installed on this server') }}">
                            @foreach ($pickerMissing as $option)
                                <option value="{{ $option['key'] }}" disabled>{{ $option['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <p class="{{ $pickerHelp }}">
                    {{ __('Only runtimes this server actually has can be selected. Install another from the server workspace to unlock it here.') }}
                </p>
                <x-input-error :messages="$errors->get('runtime_choice')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="runtime_choice_version" :value="__('Version')" class="!text-xs" />
                <x-text-input id="runtime_choice_version" wire:model="runtime_choice_version"
                    class="mt-1 block w-full font-mono text-sm" placeholder="{{ $pickerPlaceholder }}" />
                <p class="{{ $pickerHelp }}">{{ $pickerHint ?: __('Leave blank to use the server default.') }}</p>
                <x-input-error :messages="$errors->get('runtime_choice_version')" class="mt-1" />
            </div>
        </div>

        {{-- Only reverse-proxied runtimes need these. SetSiteRuntime refuses the
             switch without them, because the vhost would otherwise proxy to a
             port nothing listens on. --}}
        @if ($pickerProxied)
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="runtime_start_command" :value="__('Start command')" class="!text-xs" />
                    <x-text-input id="runtime_start_command" wire:model="runtime_start_command"
                        class="mt-1 block w-full font-mono text-sm" placeholder="npm run start" />
                    <p class="{{ $pickerHelp }}">{{ __('Long-running command the site is served from.') }}</p>
                    <x-input-error :messages="$errors->get('runtime_start_command')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="runtime_internal_port" :value="__('Internal port')" class="!text-xs" />
                    <x-text-input id="runtime_internal_port" wire:model="runtime_internal_port"
                        class="mt-1 block w-full font-mono text-sm" placeholder="3000" inputmode="numeric" />
                    <p class="{{ $pickerHelp }}">{{ __('Port the process listens on; the web server proxies to it.') }}</p>
                    <x-input-error :messages="$errors->get('runtime_internal_port')" class="mt-1" />
                </div>
            </div>
        @endif
    </div>
</form>
@endif
