{{--
    Fleet card Deploy control — the per-server twin of the site deploy sidebar
    (App\Livewire\Sites\DeployControl). Expects $server and $deployTargets (from
    Servers\Index::buildDeployTargets) in scope. Renders nothing for servers with
    no deployable sites (e.g. database / cache boxes).

    One button: opens the pick-sites modal (or deploys straight away when there's
    only one target). The old per-site dropdown + "Sync" button are gone — the
    modal's multi-select covers both. Selection logic lives in
    {@see App\Livewire\Concerns\WatchesSiteDeploys::openServerDeploy()}.
--}}
@php $deploy = $deployTargets[$server->id] ?? null; @endphp
@if ($deploy)
    <button
        type="button"
        wire:click="openServerDeploy('{{ $server->id }}')"
        wire:loading.attr="disabled"
        wire:target="openServerDeploy('{{ $server->id }}')"
        title="{{ __('Deploy this host') }}"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
    >
        <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" wire:loading.remove wire:target="openServerDeploy('{{ $server->id }}')" aria-hidden="true" />
        <span wire:loading wire:target="openServerDeploy('{{ $server->id }}')" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
        {{ __('Deploy') }}
    </button>
@endif
