@php
    use App\Models\AuditLog;
    use App\Models\Site;

    // Read-only audit trail scoped to this site. AuditLog rows can
    // be subject-typed either as the Site itself (env / domain /
    // promote / rollback changes) or as related Edge resources that
    // belong to this site, so we filter by morphed subject + an
    // action prefix to catch the broader Edge surface (cache purges,
    // image-secret rotation, etc.).
    $auditQuery = AuditLog::query()
        ->where('subject_type', Site::class)
        ->where('subject_id', $site->id)
        ->orWhere(function ($q) use ($site) {
            $q->where('organization_id', $site->organization_id)
                ->where('action', 'like', 'site.edge.%');
        })
        ->orderByDesc('created_at')
        ->limit(100);

    $entries = $auditQuery->with('user')->get();
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                    <x-heroicon-o-clipboard-document-list class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                    {{ __('Audit log') }}
                </h3>
                <p class="mt-0.5 text-sm text-brand-moss">
                    {{ __('Who changed what on this Edge site — env vars, domains, deploys, rollbacks, promotes, access rules, deploy hooks.') }}
                </p>
            </div>
            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Read-only · last 100') }}
            </span>
        </div>
    </div>

    @if ($entries->isEmpty())
        <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
            {{ __('No audited events yet.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-2 sm:px-6">{{ __('When') }}</th>
                        <th class="px-4 py-2">{{ __('Actor') }}</th>
                        <th class="px-4 py-2">{{ __('Action') }}</th>
                        <th class="px-4 py-2">{{ __('Details') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($entries as $entry)
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-2 text-brand-moss sm:px-6">
                                <span title="{{ $entry->created_at?->toIso8601String() }}">
                                    {{ $entry->created_at?->diffForHumans() ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-brand-ink">
                                {{ $entry->user?->name ?? __('System') }}
                                @if ($entry->user?->email)
                                    <span class="block font-mono text-[10px] text-brand-mist">{{ $entry->user->email }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-[11px] text-brand-ink">{{ $entry->action }}</td>
                            <td class="px-4 py-2 text-brand-moss">
                                @php
                                    $details = [];
                                    foreach ((array) ($entry->new_values ?? []) as $key => $value) {
                                        if (is_scalar($value)) {
                                            $details[] = $key.'='.\Illuminate\Support\Str::limit((string) $value, 60);
                                        } elseif (is_array($value)) {
                                            $details[] = $key.'=['.count($value).' items]';
                                        }
                                    }
                                @endphp
                                @if ($details === [])
                                    <span class="text-brand-mist">—</span>
                                @else
                                    <span class="font-mono text-[11px]">{{ implode(' · ', array_slice($details, 0, 5)) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
