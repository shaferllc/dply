@php
    $roleClasses = match (strtolower($role)) {
        'owner' => 'border-brand-forest/30 bg-brand-forest/10 text-brand-forest',
        'admin' => 'border-amber-200 bg-amber-50 text-amber-800',
        'deployer' => 'border-sky-200 bg-sky-50 text-sky-700',
        default => 'border-brand-ink/10 bg-brand-sand/45 text-brand-moss',
    };
    $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->filter()
        ->take(2)->map(fn ($p) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p, 0, 1)))->implode('');
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="members"
            :title="$user->name"
            :description="__('What :name can do in this organization, and what they have done.', ['name' => $user->name])"
            icon="heroicon-o-user-circle"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('People'), 'href' => route('organizations.members', $organization), 'icon' => 'user-group'],
                ['label' => $user->name, 'icon' => 'user-circle'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('organizations.members', $organization) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-m-chevron-left class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                    {{ __('All people') }}
                </a>
            </x-slot:actions>

            {{-- Identity strip: who they are and what they are here. --}}
            <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-moss/15 text-sm font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $initials ?: '?' }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $user->name }}</p>
                    <p class="truncate text-xs text-brand-moss">{{ $user->email }}</p>
                </div>
                <span class="shrink-0 rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses }}">{{ $role }}</span>
            </div>

            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head dense icon="heroicon-o-rectangle-group" :title="__('Teams')" :count="$teams->count() ?: null" :note="__('Teams scope which group hears an alert. Change membership from the People directory.')" />
                @if ($teams->isEmpty())
                    <p class="px-3 py-3 text-sm text-brand-moss sm:px-4">{{ __('Not on any team in this organization.') }}</p>
                @else
                    <div class="flex flex-wrap gap-1.5 px-3 py-3 sm:px-4">
                        @foreach ($teams as $team)
                            <a
                                href="{{ route('organizations.members', $organization) }}?team={{ $team->id }}"
                                wire:navigate
                                class="inline-flex items-center gap-1 rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold text-brand-moss transition-colors hover:text-brand-ink"
                            >
                                <x-heroicon-o-rectangle-group class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ $team->name }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head dense icon="heroicon-o-key" :title="__('API tokens')" :count="$tokens->count() ?: null" :note="__('Tokens this person issued for this organization.')">
                    <x-slot:actions>
                        <a
                            href="{{ route('organizations.api-tokens', $organization) }}"
                            wire:navigate
                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                        >
                            {{ __('All tokens') }}
                            <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                        </a>
                    </x-slot:actions>
                </x-workspace-panel-head>
                @if ($tokens->isEmpty())
                    <p class="px-3 py-3 text-sm text-brand-moss sm:px-4">{{ __('No API tokens for this organization.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/5">
                        @foreach ($tokens as $token)
                            @php($expired = $token->expires_at !== null && $token->expires_at->isPast())
                            <li wire:key="member-token-{{ $token->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 sm:px-4">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium {{ $expired ? 'text-brand-mist' : 'text-brand-ink' }}">{{ $token->name }}</span>
                                    <span class="mt-0.5 block truncate font-mono text-2xs text-brand-mist">
                                        {{ $token->token_prefix }}… ·
                                        {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                                    </span>
                                </span>
                                <span @class([
                                    'inline-flex shrink-0 items-center rounded border px-1.5 py-px text-2xs font-semibold uppercase tracking-wide',
                                    'border-brand-ink/10 bg-brand-sand/40 text-brand-mist' => $expired,
                                    'border-emerald-200 bg-emerald-50 text-emerald-700' => ! $expired,
                                ])>{{ $expired ? __('Expired') : __('Active') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="last:border-b-0">
                <x-workspace-panel-head dense icon="heroicon-o-clock" :title="__('Recent activity')" :note="__('Their last 10 actions in this organization.')">
                    <x-slot:actions>
                        <a
                            href="{{ route('organizations.activity', $organization) }}"
                            wire:navigate
                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                        >
                            {{ __('Full log') }}
                            <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                        </a>
                    </x-slot:actions>
                </x-workspace-panel-head>
                @if ($activity->isEmpty())
                    <p class="px-3 py-3 text-sm text-brand-moss sm:px-4">{{ __('Nothing recorded yet.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/5">
                        @foreach ($activity as $entry)
                            @php($meta = \App\Support\AuditActionMeta::meta($entry->action))
                            <li wire:key="member-activity-{{ $entry->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 sm:px-4">
                                <span class="min-w-0 flex-1 truncate text-sm text-brand-ink">{{ $meta['label'] }}</span>
                                <span class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist" title="{{ $entry->created_at?->toDayDateTimeString() }}">{{ $entry->created_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </x-organization-shell>
    </div>
</div>
