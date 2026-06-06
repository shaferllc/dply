<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        title="Changelog"
        description="What's new in dply — new features, improvements, and fixes shipped to the platform." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="changelog" />

    <main>
        {{-- Hero --}}
        <section class="relative pt-16 pb-14 sm:pt-24 sm:pb-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/60 px-4 py-1.5 text-xs font-semibold tracking-wide text-brand-forest uppercase">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-gold opacity-60"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-brand-gold"></span>
                    </span>
                    Shipping continuously
                </p>
                <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">
                    What's new in {{ config('app.name') }}
                </h1>
                <p class="mt-6 text-lg text-brand-moss leading-relaxed">
                    Notable features, improvements, and fixes — in the order they shipped.
                </p>
            </div>
        </section>

        {{-- Entries --}}
        {{--
            HOW TO ADD AN ENTRY
            Copy a block below and paste it at the top of the $entries array.
            Each entry:
              'date'    => display date string, e.g. 'June 5, 2026'
              'tags'    => subset of ['new', 'improved', 'fixed', 'security']
              'title'   => short headline
              'summary' => one-sentence description
              'items'   => array of bullet strings (empty array for no bullets)
        --}}
        @php
            $entries = [
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Deploy Clone Auth Fix',
                    'summary' => 'Deployments now clone the bare repository using the server\'s own authenticated remote URL, avoiding failures when the local remote uses an SSH URL the server can\'t access.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Atomic Release Deploys',
                    'summary' => 'Deploys now build immutable releases and flip an atomic current symlink across web and worker hosts, preventing long-running workers from serving stale code and breaking queued-job deserialization.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Fix Site Setup Console Rendering',
                    'summary' => 'Corrected a Blade templating error that could prevent the pre-flight job console from rendering during site setup.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Faster Repository Identity Lookups',
                    'summary' => 'Git provider identity lookups are now memoized per request, reducing duplicate database queries when rendering site source-control views.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Prevent Repo Page Crashes',
                    'summary' => 'Repository and commit listings no longer error out when a stored Git token can\'t be decrypted, and duplicate identity lookups during a page render are now cached.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Fix Setup Tab Redirect Crash',
                    'summary' => 'Fixed a crash when loading a site with a stale setup tab link after first-deploy setup had already completed.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Live Setup Job Console',
                    'summary' => 'The site setup wizard now shows a live console streaming the pre-flight job\'s progress and the exact reason it stalls or fails.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Keyboard-Navigable Repository Picker',
                    'summary' => 'The repository picker now supports arrow-key navigation and Enter-to-select, and the ⌘K command palette is available on marketing pages while signed in.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Unified Git Repository Picker',
                    'summary' => 'The Git repository picker now behaves consistently across the choose-app, custom-site create, and repository connection flows.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Default Branch Fallback Notice',
                    'summary' => 'Repository commit views now show a dismissible notice when a missing configured branch falls back to the repo\'s default branch.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Repo Commits Branch Fallback',
                    'summary' => 'Repository commit views now fall back to the repository\'s default branch with a notice when the configured branch no longer exists, instead of showing an error.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Repository Commit Retry And Account',
                    'summary' => 'The repository commits view now shows a retry button when commits fail to load and displays which linked account answered the read, with a quick link to change it.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Repository Read Account Visibility',
                    'summary' => 'The repository overview now shows which linked Git account answered each read and persists the account choice immediately so commits, branches, and files resolve to the selected identity.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Repository URL Field Styling',
                    'summary' => 'The repository URL input now uses the shared text input component for consistent styling.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Refined Repository Account Selector',
                    'summary' => 'The linked source-control account dropdown now uses the standard styled select component for a consistent appearance.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Repository Panel Retry Button',
                    'summary' => 'Repository commits and README error states now offer a Retry button to re-fetch the data without reloading the page.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Recover From Stalled Setup Scans',
                    'summary' => 'A site setup pre-flight scan that stalls now shows a manual re-scan button so you can unstick the wizard and proceed to deploy.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Stale Job Crash Prevention',
                    'summary' => 'Server remote-access tracking no longer errors when a stale release leaves a queued job\'s command class unresolved.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Supervisor Program Installs',
                    'summary' => 'Supervisor program configs now install correctly under non-root SSH users and resolve their working directory from the attached site, preventing silent install failures and stale imported paths.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Clone Server From Configuration Tab',
                    'summary' => 'The clone-server action is now available on both the Manage and Configuration workspaces, and single-daemon re-sync reports whether the program actually started running or failed with a reason.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Cron Presets And Daemon Re-Sync',
                    'summary' => 'Cron jobs gain a one-click library of common Laravel artisan and generic command presets, and daemon programs missing from Supervisor can now be re-registered on the server with a new Sync action.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Server CLI Tab And Firewall Bundle Removal',
                    'summary' => 'Adds a CLI tab for installing and managing servers from your terminal, and lets you remove bundled firewall templates with per-rule status while always preserving the SSH lifeline rule.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Refresh Server Private IP',
                    'summary' => 'You can now re-query DigitalOcean and Hetzner for a server\'s private IP from the connection settings, with live certificate scans now timing out gracefully instead of spinning forever.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Search And Filter Sites',
                    'summary' => 'The sites list now supports search, status filtering, sorting, and a summary stat strip, with dashboard cards linking through to servers and fleet health.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Refreshed Realtime Coming-Soon Panel',
                    'summary' => 'The Realtime coming-soon page now shows a richer preview with a terminal demo and feature highlights, and the Deploy sync entry is hidden from navigation.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Per-Service Pulse Server Cards',
                    'summary' => 'The Pulse dashboard now shows dedicated cards for Redis, database, and worker servers with live CPU, memory, and disk metrics, including those infrastructure hosts that don\'t run the dply app.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Backups Empty-State Copy Fix',
                    'summary' => 'Fixed a grammatical typo in the empty-state message shown when no database backup schedules exist.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Realtime Coming Soon Preview',
                    'summary' => 'The Realtime page now shows a preview of the managed Pusher-compatible WebSocket relay when the feature isn\'t yet enabled for your organization.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Drop Reverb Health Check Link',
                    'summary' => 'Removed the unused Reverb health check link from the admin Operations dashboard.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Backups Dashboard And Controls',
                    'summary' => 'The Backups page now shows backup health metrics, schedules you can pause or run on demand, recent runs, and storage destinations when the feature is enabled.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Backups Marked Coming Soon',
                    'summary' => 'The Backups section now appears under the main Browse menu as a coming-soon feature, and server-side broadcast events are correctly proxied to Reverb over the site\'s vhost.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Edge & Cloud Hosting Features',
                    'summary' => 'The features page now showcases Edge and Cloud hosting—container apps, serverless functions, managed realtime, and CDN storage—alongside the new PHP CLI and worker-pool details.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Public Changelog Page Entries',
                    'summary' => 'Deploys now publish titled entries to the public changelog page alongside CHANGELOG.md.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Changelog',
                    'summary' => 'This page.',
                    'items'   => [],
                ],
            ];

            $tagStyles = [
                'new'      => 'bg-brand-forest/10 text-brand-forest',
                'improved' => 'bg-brand-sage/20 text-brand-sage/90',
                'fixed'    => 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200/70',
                'security' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200/70',
            ];
            $tagLabels = [
                'new'      => 'New',
                'improved' => 'Improved',
                'fixed'    => 'Fixed',
                'security' => 'Security',
            ];
        @endphp

        <section class="px-4 pb-24 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl">
                <div class="relative">
                    {{-- Vertical timeline line --}}
                    <div class="absolute left-0 top-0 bottom-0 hidden w-px bg-gradient-to-b from-brand-ink/15 via-brand-ink/8 to-transparent sm:block" aria-hidden="true"></div>

                    <ol class="space-y-10 sm:pl-8">
                        @foreach ($entries as $entry)
                            <li class="relative">
                                {{-- Timeline dot --}}
                                <span class="absolute -left-[calc(2rem+0.3125rem)] top-1/2 -translate-y-1/2 hidden h-2.5 w-2.5 rounded-full bg-brand-sage ring-4 ring-brand-sage/15 sm:block" aria-hidden="true"></span>

                                <article class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm sm:p-8">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <time class="text-xs font-medium text-brand-mist">{{ $entry['date'] }}</time>
                                        @foreach ($entry['tags'] as $tag)
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $tagStyles[$tag] ?? '' }}">
                                                {{ $tagLabels[$tag] ?? $tag }}
                                            </span>
                                        @endforeach
                                    </div>

                                    <h2 class="mt-3 text-lg font-semibold text-brand-ink">{{ $entry['title'] }}</h2>
                                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $entry['summary'] }}</p>

                                    @if (! empty($entry['items']))
                                        <ul class="mt-4 space-y-1.5">
                                            @foreach ($entry['items'] as $item)
                                                <li class="flex items-start gap-2 text-sm text-brand-moss">
                                                    <x-heroicon-m-chevron-right class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                    <span>{!! $item !!}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </article>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="border-t border-brand-ink/10 py-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-white/40 to-brand-sand/20">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-2xl font-bold tracking-tight text-brand-ink sm:text-3xl">Built for teams that ship</h2>
                <p class="mt-4 text-brand-moss leading-relaxed">Try dply free on infrastructure you already control — no credit card until you're ready to standardize.</p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors">Go to dashboard</a>
                        <a href="{{ route('roadmap') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/70 text-brand-ink text-sm font-semibold hover:border-brand-sage/40 hover:bg-white transition-colors">View roadmap</a>
                    @else
                        <a href="{{ route('register') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors">Start free trial</a>
                        <a href="{{ route('roadmap') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/70 text-brand-ink text-sm font-semibold hover:border-brand-sage/40 hover:bg-white transition-colors">View roadmap</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
