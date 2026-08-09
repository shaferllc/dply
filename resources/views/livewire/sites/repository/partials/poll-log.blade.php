@php
    /** @var list<array{at?: string, sha?: string|null, sha_short?: string|null, outcome?: string, message?: string, http_status?: int|null, error_kind?: string|null}> $pollLog */
    $pollLog = is_array($pollLog ?? null) ? $pollLog : [];
    $showPollLog = ($isPoll ?? false) || $pollLog !== [];
    $outcomeStyles = [
        'unchanged' => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10',
        'deploy_queued' => 'bg-emerald-50 text-emerald-800 ring-emerald-200/70',
        'skipped_in_progress' => 'bg-amber-50 text-amber-900 ring-amber-200/70',
        'error' => 'bg-rose-50 text-rose-900 ring-rose-200/70',
    ];
    $outcomeLabels = [
        'unchanged' => __('Unchanged'),
        'deploy_queued' => __('Deploy queued'),
        'skipped_in_progress' => __('Skipped'),
        'error' => __('Error'),
    ];

    $entryIsAuthIssue = static function (array $entry): bool {
        $kind = is_string($entry['error_kind'] ?? null) ? (string) $entry['error_kind'] : '';
        if (in_array($kind, ['auth', 'no_account'], true)) {
            return true;
        }
        $status = $entry['http_status'] ?? null;
        if (in_array((int) $status, [401, 403], true)) {
            return true;
        }
        $msg = strtolower((string) ($entry['message'] ?? ''));

        return str_contains($msg, 'http 401')
            || str_contains($msg, 'http 403')
            || str_contains($msg, 'denied access')
            || str_contains($msg, 'no linked source-control');
    };

    $hasAuthIssue = false;
    $authStatus = null;
    $authIsNoAccount = false;
    foreach ($pollLog as $entry) {
        if (! is_array($entry) || ! $entryIsAuthIssue($entry)) {
            continue;
        }
        $hasAuthIssue = true;
        $kind = is_string($entry['error_kind'] ?? null) ? (string) $entry['error_kind'] : '';
        if ($kind === 'no_account' || str_contains(strtolower((string) ($entry['message'] ?? '')), 'no linked source-control')) {
            $authIsNoAccount = true;
        }
        $status = $entry['http_status'] ?? null;
        if ($status === null) {
            if (preg_match('/\bHTTP\s+(401|403)\b/i', (string) ($entry['message'] ?? ''), $m)) {
                $status = (int) $m[1];
            }
        }
        if ($authStatus === null && in_array((int) $status, [401, 403], true)) {
            $authStatus = (int) $status;
        }
    }

    $displayRows = [];
    foreach ($pollLog as $entry) {
        if (! is_array($entry)) {
            continue;
        }
        $outcome = is_string($entry['outcome'] ?? null) ? (string) $entry['outcome'] : 'unchanged';
        $msg = is_string($entry['message'] ?? null) ? (string) $entry['message'] : '';
        $key = $outcome.'|'.$msg;
        $last = $displayRows !== [] ? $displayRows[array_key_last($displayRows)] : null;
        if (is_array($last) && ($last['_key'] ?? null) === $key) {
            $displayRows[array_key_last($displayRows)]['_count'] = (int) ($last['_count'] ?? 1) + 1;
            continue;
        }
        $displayRows[] = $entry + ['_key' => $key, '_count' => 1];
    }

    $sourceControlUrl = route('profile.source-control');
@endphp
@if ($showPollLog)
    <div class="space-y-2.5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Poll log') }}</p>
                @if ($pollLog !== [])
                    <p class="mt-0.5 text-xs text-brand-moss">
                        {{ trans_choice('{1} :count recent check|[2,*] :count recent checks', count($pollLog), ['count' => count($pollLog)]) }}
                    </p>
                @endif
            </div>
            @if ($isPoll ?? false)
                <button
                    type="button"
                    wire:click="checkQuickDeployPollNow"
                    wire:loading.attr="disabled"
                    wire:target="checkQuickDeployPollNow"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-brand-sand/40 px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/70 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="checkQuickDeployPollNow" />
                    <span wire:loading.remove wire:target="checkQuickDeployPollNow">{{ __('Check now') }}</span>
                    <span wire:loading wire:target="checkQuickDeployPollNow">{{ __('Checking…') }}</span>
                </button>
            @endif
        </div>

        @if ($hasAuthIssue)
            <div class="flex items-start gap-2.5 rounded-lg border border-amber-200/80 bg-amber-50/90 px-3 py-2.5">
                <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <div class="min-w-0 text-xs leading-relaxed text-amber-950">
                    <p class="font-semibold text-amber-950">
                        @if ($authIsNoAccount)
                            {{ __('Poll needs a linked source-control account') }}
                        @elseif ($authStatus === 403)
                            {{ __('Source control denied access (HTTP 403)') }}
                        @else
                            {{ __('Source control authorization failed (HTTP 401)') }}
                        @endif
                    </p>
                    <p class="mt-0.5 text-amber-900/90">
                        @if ($authIsNoAccount)
                            {{ __('Connect GitHub, GitLab, or Bitbucket so dply can read the deploy branch tip, then use Check now.') }}
                        @else
                            {{ __('The linked token or OAuth session can’t read this repository. Reconnect or refresh it in Source control, then Check now.') }}
                        @endif
                        <a href="{{ $sourceControlUrl }}" wire:navigate class="font-semibold underline underline-offset-2 hover:text-amber-950">
                            {{ __('Open Source control') }}
                        </a>
                    </p>
                </div>
            </div>
        @endif

        @if ($pollLog === [])
            <div class="flex items-start gap-2.5 border-t border-brand-ink/10 pt-2.5">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-sand/40 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-clock class="h-3.5 w-3.5" aria-hidden="true" />
                </span>
                <p class="text-xs leading-relaxed text-brand-moss">
                    {{ __('No checks yet — waiting for the next poll tick, or use Check now.') }}
                </p>
            </div>
        @else
            <ul class="max-h-56 divide-y divide-brand-ink/5 overflow-y-auto border-t border-brand-ink/10">
                @foreach ($displayRows as $entry)
                    @php
                        $outcome = is_string($entry['outcome'] ?? null) ? (string) $entry['outcome'] : 'unchanged';
                        $style = $outcomeStyles[$outcome] ?? $outcomeStyles['unchanged'];
                        $label = $outcomeLabels[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
                        $atRaw = is_string($entry['at'] ?? null) ? (string) $entry['at'] : null;
                        $atLabel = $atRaw;
                        if ($atRaw) {
                            try {
                                $atLabel = \Illuminate\Support\Carbon::parse($atRaw)->timezone(config('app.timezone'))->format('M j, g:i A');
                            } catch (\Throwable) {
                                $atLabel = $atRaw;
                            }
                        }
                        $shaShort = is_string($entry['sha_short'] ?? null) && $entry['sha_short'] !== ''
                            ? (string) $entry['sha_short']
                            : (is_string($entry['sha'] ?? null) && $entry['sha'] !== '' ? \Illuminate\Support\Str::substr((string) $entry['sha'], 0, 7) : null);
                        $msg = is_string($entry['message'] ?? null) ? (string) $entry['message'] : '';
                        $count = (int) ($entry['_count'] ?? 1);
                        $isAuthRow = $entryIsAuthIssue($entry);
                    @endphp
                    <li class="flex gap-3 px-0 py-2 text-xs leading-snug">
                        <span class="w-[6.5rem] shrink-0 tabular-nums text-brand-mist">{{ $atLabel ?? '—' }}</span>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 ring-inset {{ $style }}">{{ $label }}</span>
                                @if ($shaShort)
                                    <span class="font-mono text-xs text-brand-ink">{{ $shaShort }}</span>
                                @endif
                                @if ($count > 1)
                                    <span class="text-2xs font-medium text-brand-mist">×{{ $count }}</span>
                                @endif
                            </div>
                            @if ($msg !== '' && ! ($hasAuthIssue && $isAuthRow))
                                <p class="text-brand-moss">{{ $msg }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
