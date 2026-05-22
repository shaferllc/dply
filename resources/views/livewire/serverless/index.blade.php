<div>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Serverless'), 'icon' => 'sparkles'],
        ]" />

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-brand-ink">{{ __('Serverless functions') }}</h1>
                <p class="mt-1 text-sm text-brand-moss">{{ __('HTTP-triggered functions deployed to DigitalOcean Functions.') }}</p>
            </div>
            <a href="{{ route('serverless.create') }}" wire:navigate
               class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('New function') }}
            </a>
        </div>

        @if ($functions->isEmpty())
            <div class="dply-card mt-6 p-10 text-center">
                <h2 class="text-base font-bold text-brand-ink">{{ __('No functions yet') }}</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-brand-moss">
                    {{ __('Deploy an HTTP-triggered function from a Git repository — point dply at a repo and it handles the build, runtime, and invocation URL.') }}
                </p>
                <a href="{{ route('serverless.create') }}" wire:navigate
                   class="mt-4 inline-flex items-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                    {{ __('Create a function') }}
                </a>
            </div>
        @else
            <div class="dply-card mt-6 overflow-hidden">
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($functions as $function)
                        @php
                            $cfg = is_array($function->meta['serverless'] ?? null) ? $function->meta['serverless'] : [];
                            $live = $function->status === \App\Models\Site::STATUS_FUNCTIONS_ACTIVE;
                            $lastDeployedAt = $cfg['last_deployed_at'] ?? null;
                        @endphp
                        <li class="flex flex-wrap items-center gap-4 px-5 py-4 sm:px-6">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('sites.show', ['server' => $function->server_id, 'site' => $function->id]) }}" wire:navigate
                                       class="truncate text-sm font-semibold text-brand-ink hover:underline">{{ $function->name }}</a>
                                    <span @class([
                                        'inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                                        'bg-brand-forest/15 text-brand-forest' => $live,
                                        'bg-brand-gold/20 text-brand-ink' => ! $live,
                                    ])>{{ $live ? __('Live') : __('Deploying') }}</span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-brand-moss">
                                    <span class="font-mono">{{ $function->git_repository_url ?: '—' }}</span>
                                    @if (($cfg['runtime'] ?? '') !== '')<span class="text-brand-moss/60"> · {{ $cfg['runtime'] }}</span>@endif
                                </p>
                            </div>
                            <div class="text-xs text-brand-moss">
                                {{ $lastDeployedAt ? __('Deployed :ago', ['ago' => \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans()]) : __('Never deployed') }}
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('serverless.journey', ['server' => $function->server_id, 'site' => $function->id]) }}" wire:navigate
                                   class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                                    {{ __('Journey') }}
                                </a>
                                <a href="{{ route('sites.show', ['server' => $function->server_id, 'site' => $function->id]) }}" wire:navigate
                                   class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest">
                                    {{ __('Open') }}
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
