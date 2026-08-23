{{-- The CLI nudge and the unlink error band, shared by every layout. --}}
        {{-- Prefer the terminal? Point at the CLI install + sign-in steps on
             /profile/cli instead of duplicating them here. --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-3 py-2 sm:px-4">
            <p class="flex min-w-0 items-center gap-1.5 text-xs text-brand-moss">
                <x-heroicon-o-command-line class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                {{ __('Prefer the command line? Link repos and deploy from your terminal.') }}
            </p>
            <a href="{{ route('profile.cli') }}" wire:navigate class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Install the CLI') }}
            </a>
        </div>

        @error('unlink')
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <div class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs text-red-800" role="alert">
                    <span class="inline-flex items-center gap-1.5 font-semibold">
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $message }}
                    </span>
                </div>
            </div>
        @enderror

{{-- These connections are yours. A credential that must outlive you — the
     machine user a site keeps deploying with after you leave — belongs to the
     organization. See docs/adr/org-owned-git-credentials.md. --}}
@if (auth()->user()?->currentOrganization())
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <p class="flex min-w-0 items-center gap-1.5 text-xs text-brand-moss">
            <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
            {{ __('These connections are personal. A shared token your whole organization can deploy with lives with its other credentials.') }}
        </p>
        <a
            href="{{ route('organizations.credentials', ['organization' => auth()->user()->currentOrganization(), 'filter' => 'git']) }}"
            wire:navigate
            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
        >
            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Organization credentials') }}
        </a>
    </div>
@endif
