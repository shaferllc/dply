@props([
    'title',
    /** @var list<array<string, mixed>> */
    'rows' => [],
    'error' => null,
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'description' => null,
    'apiReady' => true,
    'columns' => null,
])

@php
    $description ??= __('Read-only inventory from the connected control plane API.');
    $columns ??= [
        ['key' => 'name', 'label' => __('Name')],
        ['key' => 'id', 'label' => __('ID'), 'mono' => true],
        ['key' => 'detail', 'label' => __('Details')],
    ];
@endphp

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    <div>
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Production') }} · {{ $title }}</h1>
        <p class="mt-1 text-sm text-brand-moss">{{ $description }}</p>
    </div>

    @if ($error)
        <x-alert tone="danger">{{ $error }}</x-alert>
    @endif

    @unless ($apiReady)
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-6 py-16 text-center">
            <p class="text-sm font-semibold text-brand-ink">{{ __('No list API for this product line yet') }}</p>
            <p class="mx-auto mt-2 max-w-md text-sm text-brand-moss">
                {{ __('Nav is wired. When the control-plane API exposes inventory for :title, it will load here.', ['title' => $title]) }}
            </p>
        </div>
    @else
        <div class="dply-card overflow-hidden">
            @if (count($rows) === 0)
                <div class="px-6 py-12 text-center text-sm text-brand-moss">{{ __('No records returned from production.') }}</div>
            @else
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                        <tr>
                            @foreach ($columns as $col)
                                <th class="px-4 py-3">{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-brand-sand/20">
                                @foreach ($columns as $col)
                                    @php
                                        $value = $row[$col['key']] ?? '—';
                                        if ($col['key'] === 'detail' && ($value === '—' || $value === null)) {
                                            $value = $row['status'] ?? $row['role'] ?? $row['hostname'] ?? $row['slug'] ?? '—';
                                            if (array_key_exists('servers_count', $row)) {
                                                $value = ((int) $row['servers_count']).' '.__('servers');
                                            }
                                        }
                                    @endphp
                                    <td @class(['px-4 py-3', 'font-mono text-xs text-brand-mist' => ! empty($col['mono']), 'font-semibold text-brand-ink' => ($col['key'] ?? '') === 'name'])>
                                        {{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endunless
</div>
