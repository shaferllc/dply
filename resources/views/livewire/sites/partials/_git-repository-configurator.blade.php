{{--
    Shared "Configure Git repository" picker: source toggle (connected provider /
    paste a URL) + account select + searchable repository dropdown + manual URL.

    Backed by the App\Livewire\Concerns\Sites\ConfiguresGitRepository trait — the
    host component must `use` it (and RefreshesLinkedSourceControlAccounts) so the
    property names below resolve.

    Deliberately does NOT render the "Ref to deploy" block: hosts differ on whether
    a ref picker exists and how it opens, so each renders its own ref UI after this
    include.

    Params:
      - $idPrefix       string  unique prefix for input ids (default 'gitcfg')
      - $showConnectLink bool   render the "Connect a provider" link (default true)
      - $required       bool    mark the account/repo/URL labels required (default true)
      - $reposLoading   bool    repo list is still being fetched — show a spinner
                                instead of the "no repositories" message (default false)
--}}
@php
    $idPrefix = $idPrefix ?? 'gitcfg';
    $showConnectLink = $showConnectLink ?? true;
    $required = $required ?? true;
    $reposLoading = $reposLoading ?? false;
@endphp

<div class="flex flex-wrap items-center justify-between gap-3">
    @if (count($linkedSourceControlAccounts) > 0)
        {{-- Source toggle: connected provider vs pasted URL --}}
        <div class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-cream/60 p-1">
            <button type="button" wire:click="$set('repo_source', 'provider')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                    'bg-white text-brand-ink shadow-sm' => $repo_source === 'provider',
                    'text-brand-moss hover:text-brand-ink' => $repo_source !== 'provider',
                ])>
                <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                {{ __('Connected provider') }}
            </button>
            <button type="button" wire:click="$set('repo_source', 'manual')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                    'bg-white text-brand-ink shadow-sm' => $repo_source === 'manual',
                    'text-brand-moss hover:text-brand-ink' => $repo_source !== 'manual',
                ])>
                <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                {{ __('Paste a URL') }}
            </button>
        </div>
    @else
        <span></span>
    @endif
    @if ($showConnectLink)
        <x-connect-provider-link>{{ count($linkedSourceControlAccounts) > 0
            ? __('Connect another account')
            : __('Connect a provider') }} &rarr;</x-connect-provider-link>
    @endif
</div>

@if ($repo_source === 'provider' && count($linkedSourceControlAccounts) > 0)
    <div>
        <div class="flex items-center gap-2">
            <x-input-label for="{{ $idPrefix }}-account" :value="__('Account')" :required="$required" />
            <span wire:loading wire:target="source_control_account_id" class="inline-flex items-center gap-1 text-xs font-medium text-brand-moss">
                <x-spinner size="sm" />
                {{ __('Loading repositories…') }}
            </span>
        </div>
        <select id="{{ $idPrefix }}-account" wire:model.live="source_control_account_id"
            wire:loading.attr="disabled" wire:target="source_control_account_id"
            class="dply-input mt-1.5 disabled:cursor-progress disabled:opacity-60">
            @foreach ($linkedSourceControlAccounts as $account)
                <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('source_control_account_id')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="{{ $idPrefix }}-repo" :value="__('Repository')" :required="$required" />
        @if (count($availableRepositories) > 0)
            <x-repo-combobox
                :repositories="$availableRepositories"
                property="repository_selection"
                target="source_control_account_id"
                trigger-id="{{ $idPrefix }}-repo"
                :selected="$repository_selection"
                :placeholder="__('Select repository')"
                class="mt-1.5"
            />
        @elseif ($reposLoading)
            <p class="mt-1.5 flex items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-3 py-2.5 text-xs text-brand-moss">
                <x-spinner size="sm" />
                <span>{{ __('Loading repositories…') }}</span>
            </p>
        @else
            <p class="mt-1.5 flex items-start gap-1.5 rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-3 py-2.5 text-xs text-brand-moss">
                <x-heroicon-o-information-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                <span>{{ __('No repositories found for this account. Switch to “Paste a URL”, or pick another account.') }}</span>
            </p>
        @endif
        <x-repo-access-hint :accounts="$linkedSourceControlAccounts" :selected="$source_control_account_id" />
        <x-input-error :messages="$errors->get('repository_selection')" class="mt-2" />
    </div>
@else
    <div>
        <x-input-label for="{{ $idPrefix }}-url" :value="__('Repository URL')" :required="$required" />
        <div class="relative mt-1.5">
            <x-text-input
                id="{{ $idPrefix }}-url"
                type="text"
                wire:model.live.debounce.500ms="git_repository_url"
                autocomplete="off"
                class="font-mono pr-9"
                placeholder="https://github.com/acme/app"
            />
            <div wire:loading wire:target="git_repository_url"
                class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                <x-spinner size="sm" class="text-brand-moss" />
            </div>
        </div>

        {{-- Repo found: show metadata card + branch picker --}}
        @if ($repoScanState === 'found' && $scannedRepoName !== '')
            <div wire:loading.remove wire:target="git_repository_url"
                class="mt-2 rounded-xl border border-brand-sage/30 bg-brand-cream/70 p-3">
                <div class="flex items-start gap-2.5">
                    <x-oauth-provider-icon
                        :provider="$scannedProvider ?: 'github'"
                        size="h-4 w-4"
                        class="mt-0.5 shrink-0 text-brand-ink"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="font-mono text-sm font-semibold text-brand-ink">{{ $scannedRepoName }}</span>
                            <span @class([
                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-bold uppercase tracking-wide',
                                'bg-brand-sage/15 text-brand-forest' => $scannedVisibility === 'public',
                                'bg-amber-100 text-amber-800' => $scannedVisibility !== 'public',
                            ])>{{ $scannedVisibility === 'public' ? __('Public') : __('Private') }}</span>
                            @if ($scannedStars > 0)
                                <span class="flex items-center gap-0.5 text-2xs text-brand-moss">
                                    <x-heroicon-s-star class="h-3 w-3 text-brand-gold" aria-hidden="true" />
                                    {{ number_format($scannedStars) }}
                                </span>
                            @endif
                        </div>
                        @if ($scannedDescription !== '')
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $scannedDescription }}</p>
                        @endif
                        @if (count($scannedBranches) > 0)
                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-xs font-medium text-brand-ink">{{ __('Branch') }}</span>
                                <select wire:model.live="git_branch"
                                    class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink">
                                    @foreach ($scannedBranches as $branchName)
                                        <option value="{{ $branchName }}" @selected($branchName === $git_branch)>
                                            {{ $branchName }}{{ $branchName === $scannedDefaultBranch ? ' ('.strtolower(__('default')).')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @elseif ($repoScanState === 'not_found')
            <p wire:loading.remove wire:target="git_repository_url"
                class="mt-2 flex items-center gap-1.5 text-xs text-red-600">
                <x-heroicon-o-x-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Repository not found. Check the URL or try a different format.') }}
            </p>
        @elseif ($repoScanState === 'error')
            <p wire:loading.remove wire:target="git_repository_url"
                class="mt-2 flex items-center gap-1.5 text-xs text-amber-700">
                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Could not scan repository. It may be private or temporarily unavailable.') }}
            </p>
        @endif

        <x-input-error :messages="$errors->get('git_repository_url')" class="mt-2" />
    </div>
@endif
