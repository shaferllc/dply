@php
    // Shared by every mise-managed runtime (node / python / go / bun / deno /
    // java). Before this existed, SiteSettingsSidebar::runtimeTabsFor() returned
    // null for all of them, so a Node site got an Overview tab and nothing else.
    $catalog = (array) config('server_manage.mise_runtimes', []);
    $runtimeKey = (string) ($site->runtime ?? '');
    $entry = $catalog[$runtimeKey] ?? [];
    $runtimeLabel = (string) ($entry['label'] ?? ucfirst($runtimeKey));
    $versionPlaceholder = (string) ($entry['placeholder'] ?? '');
    $versionHint = (string) ($entry['hint'] ?? '');

    $options = $this->siteRuntimeOptions();
    $installed = array_values(array_filter($options, fn (array $o) => $o['installed']));
    $notInstalled = array_values(array_filter($options, fn (array $o) => ! $o['installed']));

    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-xs text-brand-moss';
@endphp

<form wire:submit="switchSiteRuntime" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-command-line"
        :title="$runtimeLabel . ' ' . __('runtime')"
        :note="__('Changing the runtime rewrites this site\'s web server config, so the box matches the record.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="switchSiteRuntime">
                <span wire:loading.remove wire:target="switchSiteRuntime">{{ __('Save') }}</span>
                <span wire:loading wire:target="switchSiteRuntime">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }} space-y-4">
        <div class="max-w-md">
            <x-input-label for="runtime_choice" :value="__('Runtime')" class="!text-xs" />
            <select id="runtime_choice" wire:model.live="runtime_choice"
                class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-forest focus:ring-brand-forest">
                <optgroup label="{{ __('Installed on this server') }}">
                    @foreach ($installed as $option)
                        <option value="{{ $option['key'] }}">
                            {{ $option['label'] }}@if ($option['version']) · {{ $option['version'] }}@endif
                        </option>
                    @endforeach
                </optgroup>
                @if ($notInstalled !== [])
                    <optgroup label="{{ __('Not installed yet') }}">
                        @foreach ($notInstalled as $option)
                            <option value="{{ $option['key'] }}" disabled>{{ $option['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            <p class="{{ $fieldHelp }}">
                {{ __('Only runtimes this server actually has can be selected. Install another from the server workspace to unlock it here.') }}
            </p>
            <x-input-error :messages="$errors->get('runtime_choice')" class="mt-1" />
        </div>

        <div class="max-w-md">
            <x-input-label for="runtime_choice_version" :value="__('Version')" class="!text-xs" />
            <x-text-input id="runtime_choice_version" wire:model="runtime_choice_version"
                class="mt-1 block w-full font-mono text-sm" placeholder="{{ $versionPlaceholder }}" />
            @if ($versionHint)
                <p class="{{ $fieldHelp }}">{{ $versionHint }}</p>
            @endif
            <x-input-error :messages="$errors->get('runtime_choice_version')" class="mt-1" />
        </div>

        {{-- The web server reverse-proxies to this process, so without a command
             and a port there is nothing to proxy to and the vhost would point at
             a socket that never opens. SetSiteRuntime refuses the switch. --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="runtime_start_command" :value="__('Start command')" class="!text-xs" />
                <x-text-input id="runtime_start_command" wire:model="runtime_start_command"
                    class="mt-1 block w-full font-mono text-sm" placeholder="npm run start" />
                <p class="{{ $fieldHelp }}">{{ __('Long-running command the site is served from.') }}</p>
                <x-input-error :messages="$errors->get('runtime_start_command')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="runtime_internal_port" :value="__('Internal port')" class="!text-xs" />
                <x-text-input id="runtime_internal_port" wire:model="runtime_internal_port"
                    class="mt-1 block w-full font-mono text-sm" placeholder="3000" inputmode="numeric" />
                <p class="{{ $fieldHelp }}">{{ __('Port the process listens on; the web server proxies to it.') }}</p>
                <x-input-error :messages="$errors->get('runtime_internal_port')" class="mt-1" />
            </div>
        </div>
    </div>
</form>

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="[
        ['label' => __('Set runtime'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime='.$runtimeKey.' --runtime-version='.($site->runtime_version ?: '22')],
        ['label' => __('Set start command'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --start=\'npm run start\' --port=3000'],
    ]" />
</div>
