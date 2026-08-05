{{--
    Read-only snapshot for one saved blueprint, opened from the library table.

    The table can only carry the tagline; this is where you check what a
    blueprint would actually reproduce before picking it in the create wizard —
    the pinned stack, plus the firewall rules and daemons that ride along with it.

    Receives: $blueprint (ServerBlueprint|null), $summary (ServerBlueprintSummary).
--}}
@php
    $snapshot = is_array($blueprint?->snapshot) ? $blueprint->snapshot : [];
    $extras = $blueprint !== null ? $summary->extras($snapshot) : ['firewall_rules' => 0, 'supervisor_programs' => 0, 'runtimes' => []];
    $firewallRules = is_array($snapshot['firewall_rules'] ?? null) ? $snapshot['firewall_rules'] : [];
    $programs = is_array($snapshot['supervisor_programs'] ?? null) ? $snapshot['supervisor_programs'] : [];

    // Display labels from the same mapping the tagline uses, so this never shows
    // `mysql84` under a heading that reads "MySQL 8.4". Memory sizing is left out
    // on purpose: it describes the machine it came from, not what gets built.
    $stackRows = $blueprint === null ? [] : $summary->stackLabels($snapshot);

    $metaRows = $blueprint === null ? [] : array_filter([
        __('Captured from') => $blueprint->sourceServer?->name,
        __('Role') => ucfirst((string) ($snapshot['server_role'] ?? 'application')),
        __('Install profile') => filled($snapshot['install_profile'] ?? null) ? \Illuminate\Support\Str::headline((string) $snapshot['install_profile']) : null,
        __('Saved') => $blueprint->created_at?->format('Y-m-d H:i'),
        __('Updated') => $blueprint->updated_at?->format('Y-m-d H:i'),
    ], fn ($value) => filled($value));
@endphp

<x-modal name="blueprint-details" maxWidth="2xl">
    @if ($blueprint === null)
        <div class="p-6">
            <p class="text-sm text-brand-moss">{{ __('That blueprint is no longer available.') }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" wire:click="closeDetailModal">{{ __('Close') }}</x-secondary-button>
            </div>
        </div>
    @else
        <x-workspace-panel-head
            dense
            icon="heroicon-o-document-duplicate"
            :title="$blueprint->name"
            :note="$summary->tagline($snapshot)"
            class="border-b border-brand-ink/10"
        />

        <div class="max-h-[70vh] space-y-5 overflow-y-auto px-5 py-4 sm:px-6">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('Stack') }}</h3>
                <dl class="mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                    @foreach ($stackRows as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/5 py-1">
                            <dt class="text-xs text-brand-moss">{{ $label }}</dt>
                            <dd class="text-xs font-semibold text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @foreach ($extras['runtimes'] as $runtime)
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/5 py-1">
                            <dt class="text-xs text-brand-moss">{{ __('Runtime') }}</dt>
                            <dd class="text-xs font-semibold text-brand-ink">{{ $runtime }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('Origin') }}</h3>
                <dl class="mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                    @foreach ($metaRows as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/5 py-1">
                            <dt class="text-xs text-brand-moss">{{ $label }}</dt>
                            <dd class="text-xs font-semibold text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">
                    {{ __('Firewall rules') }}
                    <span class="ms-1 font-mono text-[10px] text-brand-mist">{{ count($firewallRules) }}</span>
                </h3>
                @if ($firewallRules === [])
                    <p class="mt-1 text-xs text-brand-mist">{{ __('None — the new server keeps its provisioning defaults.') }}</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                            <thead class="bg-brand-sand/30 text-brand-moss">
                                <tr>
                                    <th class="px-3 py-1 font-medium">{{ __('Name') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('Port') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('Source') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                @foreach ($firewallRules as $rule)
                                    <tr @class(['opacity-50' => ! ($rule['enabled'] ?? true)])>
                                        <td class="px-3 py-1">{{ $rule['name'] ?? $rule['profile'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-1 font-mono text-brand-moss">
                                            {{ $rule['port'] ?? '—' }}@if (filled($rule['protocol'] ?? null))<span class="text-brand-mist">/{{ $rule['protocol'] }}</span>@endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-1 font-mono text-brand-moss">{{ $rule['source'] ?? __('any') }}</td>
                                        <td class="whitespace-nowrap px-3 py-1 text-brand-moss">{{ $rule['action'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">
                    {{ __('Daemons') }}
                    <span class="ms-1 font-mono text-[10px] text-brand-mist">{{ count($programs) }}</span>
                </h3>
                @if ($programs === [])
                    <p class="mt-1 text-xs text-brand-mist">{{ __('None captured.') }}</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                            <thead class="bg-brand-sand/30 text-brand-moss">
                                <tr>
                                    <th class="px-3 py-1 font-medium">{{ __('Program') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('Command') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('User') }}</th>
                                    <th class="px-3 py-1 font-medium">{{ __('Procs') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                @foreach ($programs as $program)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-1">{{ $program['slug'] ?? $program['program_type'] ?? '—' }}</td>
                                        <td class="max-w-sm truncate px-3 py-1 font-mono text-brand-moss" title="{{ $program['command'] ?? '' }}">{{ $program['command'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-1 text-brand-moss">{{ $program['user'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-1 font-mono text-brand-moss">{{ $program['numprocs'] ?? 1 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <x-secondary-button type="button" wire:click="closeDetailModal">{{ __('Close') }}</x-secondary-button>
        </div>
    @endif
</x-modal>
