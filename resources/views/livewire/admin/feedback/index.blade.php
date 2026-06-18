<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-brand-ink">{{ __('Feedback & bug reports') }}</h1>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Reports submitted from the in-app feedback sidebar.') }}</p>
        </div>
        @if ($newCount > 0)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-rust/10 px-3 py-1 text-xs font-semibold text-brand-rust">
                {{ trans_choice(':count new report|:count new reports', $newCount, ['count' => $newCount]) }}
            </span>
        @endif
    </div>

    {{-- Filters --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search summary, details, ref…') }}"
            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30"
        />
        <select wire:model.live="typeFilter" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30">
            <option value="all">{{ __('All types') }}</option>
            @foreach ($types as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30">
            <option value="all">{{ __('All statuses') }}</option>
            @foreach ($statuses as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="severityFilter" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30">
            <option value="all">{{ __('All severities') }}</option>
            @foreach ($severities as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- List --}}
    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
            <thead class="bg-brand-cream/60 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                <tr>
                    <th class="px-4 py-2.5">{{ __('Report') }}</th>
                    <th class="px-4 py-2.5">{{ __('Type') }}</th>
                    <th class="px-4 py-2.5">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5">{{ __('From') }}</th>
                    <th class="px-4 py-2.5">{{ __('When') }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/5">
                @forelse ($reports as $report)
                    <tr class="hover:bg-brand-sand/20">
                        <td class="px-4 py-3">
                            <button type="button" wire:click="openReport('{{ $report->id }}')" class="text-left">
                                <span class="font-medium text-brand-ink">{{ $report->title }}</span>
                                <span class="block text-xs text-brand-mist">{{ $report->reference }}</span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5">
                                <span @class([
                                    'inline-block h-1.5 w-1.5 rounded-full',
                                    'bg-brand-rust' => $report->type === 'bug',
                                    'bg-brand-sage' => $report->type === 'idea',
                                    'bg-brand-mist' => $report->type === 'question',
                                ])></span>
                                {{ $report->typeLabel() }}
                                @if ($report->severity)
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase',
                                        'bg-red-100 text-red-700' => $report->isHighPriority(),
                                        'bg-brand-sand/70 text-brand-moss' => ! $report->isHighPriority(),
                                    ])>{{ $report->severityLabel() }}</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-brand-rust/10 text-brand-rust' => $report->status === 'new',
                                'bg-brand-sand/70 text-brand-moss' => $report->status !== 'new',
                            ])>{{ $report->statusLabel() }}</span>
                        </td>
                        <td class="px-4 py-3 text-brand-moss">
                            {{ $report->user?->name ?? __('Unknown') }}
                            @if ($report->organization)
                                <span class="block text-xs text-brand-mist">{{ $report->organization->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-brand-moss" title="{{ $report->created_at }}">{{ $report->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="openReport('{{ $report->id }}')" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('View') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-brand-mist">{{ __('No reports match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $reports->links() }}</div>

    {{-- Detail / triage modal --}}
    @if ($selected)
        <div class="fixed inset-0 z-[100] overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeReport"></div>
            <div class="relative flex min-h-full items-start justify-center px-4 py-10">
                <div class="relative w-full max-w-3xl rounded-2xl border border-brand-ink/10 bg-white shadow-2xl">
                    <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ $selected->title }}</h2>
                                <span class="rounded bg-brand-sand/70 px-1.5 py-0.5 text-[11px] font-semibold text-brand-moss">{{ $selected->reference }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ $selected->typeLabel() }}@if ($selected->severityLabel()) · {{ $selected->severityLabel() }} @endif
                                · {{ $selected->created_at->format('M j, Y g:ia') }}
                            </p>
                        </div>
                        <button type="button" wire:click="closeReport" class="rounded-lg border border-brand-ink/15 bg-white p-1.5 text-brand-moss hover:bg-brand-sand/40">
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="grid gap-6 px-6 py-5 lg:grid-cols-3">
                        {{-- Left: details --}}
                        <div class="space-y-5 lg:col-span-2">
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Details') }}</h3>
                                <p class="mt-1.5 whitespace-pre-wrap text-sm text-brand-ink">{{ $selected->description }}</p>
                            </div>

                            @if ($selected->screenshot_path)
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Screenshot') }}</h3>
                                    <a href="{{ route('admin.feedback.screenshot', $selected) }}" target="_blank" rel="noopener" class="mt-1.5 block overflow-hidden rounded-lg border border-brand-ink/10">
                                        <img src="{{ route('admin.feedback.screenshot', $selected) }}" alt="{{ __('Page screenshot') }}" class="w-full" loading="lazy" />
                                    </a>
                                </div>
                            @elseif ($selected->attachments_pruned_at)
                                <div class="rounded-lg border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs text-brand-mist">
                                    {{ __('Screenshot expired and was pruned on :date.', ['date' => $selected->attachments_pruned_at->format('M j, Y')]) }}
                                </div>
                            @endif

                            @php $context = $selected->context ?? []; @endphp
                            @if (! empty($context))
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Page context') }}</h3>
                                    <dl class="mt-1.5 grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                                        @if (!empty($context['url']))
                                            <div class="sm:col-span-2"><dt class="inline text-brand-mist">{{ __('URL') }}:</dt> <dd class="inline break-all text-brand-ink">{{ $context['url'] }}</dd></div>
                                        @endif
                                        @if (!empty($context['user_agent']))
                                            <div class="sm:col-span-2"><dt class="inline text-brand-mist">{{ __('Browser') }}:</dt> <dd class="inline text-brand-ink">{{ $context['user_agent'] }}</dd></div>
                                        @endif
                                        @if (!empty($context['viewport']))
                                            <div><dt class="inline text-brand-mist">{{ __('Viewport') }}:</dt> <dd class="inline text-brand-ink">{{ $context['viewport']['width'] ?? '?' }}×{{ $context['viewport']['height'] ?? '?' }}</dd></div>
                                        @endif
                                        @if (!empty($context['language']))
                                            <div><dt class="inline text-brand-mist">{{ __('Locale') }}:</dt> <dd class="inline text-brand-ink">{{ $context['language'] }}</dd></div>
                                        @endif
                                        @if ($selected->ip_address)
                                            <div><dt class="inline text-brand-mist">{{ __('IP') }}:</dt> <dd class="inline text-brand-ink">{{ $selected->ip_address }}</dd></div>
                                        @endif
                                    </dl>
                                </div>

                                @if (! empty($context['console']))
                                    <div>
                                        <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Console errors') }} ({{ count($context['console']) }})</h3>
                                        <div class="mt-1.5 max-h-48 overflow-y-auto rounded-lg bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-brand-cream">
                                            @foreach ($context['console'] as $entry)
                                                <div class="flex gap-2">
                                                    <span @class([
                                                        'shrink-0 font-semibold',
                                                        'text-red-400' => ($entry['level'] ?? '') === 'error',
                                                        'text-amber-300' => ($entry['level'] ?? '') === 'warn',
                                                    ])>[{{ $entry['level'] ?? '?' }}]</span>
                                                    <span class="break-all">{{ $entry['message'] ?? '' }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- Right: triage --}}
                        <div class="space-y-4 lg:border-l lg:border-brand-ink/10 lg:pl-6">
                            @if ($selected->user)
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 p-3">
                                    <p class="text-xs text-brand-moss">{{ __('Reproduce this from the reporter’s view:') }}</p>
                                    <div class="mt-2">
                                        <x-impersonate-button :user="$selected->user" :label="__('Impersonate reporter')" />
                                    </div>
                                </div>
                            @endif

                            <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Triage') }}</h3>

                            <div>
                                <label class="mb-1 block text-xs text-brand-moss">{{ __('Status') }}</label>
                                <select wire:model="triageStatus" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30">
                                    @foreach ($statuses as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('triageStatus') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-xs text-brand-moss">{{ __('Assignee') }}</label>
                                <select wire:model="triageAssignee" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($admins as $admin)
                                        <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs text-brand-moss">{{ __('Admin notes') }}</label>
                                <textarea wire:model="triageNotes" rows="4" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                            </div>

                            @if ($selected->user)
                                <label class="flex items-start gap-2 text-xs text-brand-moss">
                                    <input type="checkbox" wire:model="notifyReporter" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                                    {{ __('Notify reporter when resolving / closing (sends them a bell notification with your notes)') }}
                                </label>
                            @endif

                            <button type="button" wire:click="saveTriage" class="w-full rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white hover:bg-brand-ink/90">
                                {{ __('Save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
