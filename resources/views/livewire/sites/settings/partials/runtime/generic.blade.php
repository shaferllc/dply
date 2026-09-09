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

@include('livewire.sites.settings.partials.runtime._picker')

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="[
        ['label' => __('Set runtime'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime='.$runtimeKey.' --runtime-version='.($site->runtime_version ?: '22')],
        ['label' => __('Set start command'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --start=\'npm run start\' --port=3000'],
    ]" />
</div>
