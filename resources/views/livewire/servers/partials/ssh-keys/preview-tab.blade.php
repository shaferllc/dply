            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Drift preview') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="requestSyncAuthorizedKeys"
                            wire:loading.attr="disabled"
                            wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys"
                            @disabled($syncBusy || $driftBusy)
                            title="{{ $syncBusy ? __('A sync is already running.') : ($driftBusy ? __('A drift preview is running — wait for it to finish.') : __('Apply the pending changes by writing authorized_keys on the server.')) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/10 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/15 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                            <span wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Sync now') }}</span>
                            <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="previewDiff"
                            wire:loading.attr="disabled"
                            wire:target="previewDiff"
                            @disabled($syncBusy || $driftBusy)
                            title="{{ $syncBusy ? __('A sync is in flight — wait for it to finish before refreshing the drift preview.') : ($driftBusy ? __('A drift preview is already running. Wait for it to finish.') : '') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="previewDiff" />
                            <span wire:loading wire:target="previewDiff" class="inline-flex h-4 w-4 items-center justify-center">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            <span wire:loading.remove wire:target="previewDiff">{{ __('Refresh preview') }}</span>
                            <span wire:loading wire:target="previewDiff">{{ __('Refreshing…') }}</span>
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Compares the panel’s desired keys with what is on the server now (read-only).') }}</p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('“Will add” / “Will remove” means: when you click Sync, the server\'s authorized_keys file will gain or lose that key. Adding a key in the panel doesn\'t touch the server — only Sync does.') }}
                </p>
                @if ($diff_result === null)
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" />
                        </span>
                        @if ($recentlySynced)
                            <p class="text-sm font-medium text-brand-ink">{{ __('Sync finished — drift preview cleared.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Click “Refresh preview” to confirm the server now matches the panel.') }}</p>
                        @elseif (($driftStatus ?? '') === 'completed' && ! ($driftHasChanges ?? false))
                            {{-- Last preview completed with no drift, but the structured diff has
                                 lapsed from the cache (or the page reloaded). Reflect the outcome
                                 instead of pretending nothing has run. --}}
                            <p class="text-sm font-medium text-brand-ink">{{ __('In sync — no drift.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('authorized_keys on the server matches your desired keys. Click “Refresh preview” to re-check.') }}</p>
                        @else
                            <p class="text-sm font-medium text-brand-ink">{{ __('No comparison loaded yet.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Click “Refresh preview” to compare the panel against authorized_keys on the server.') }}</p>
                        @endif
                    </div>
                @else
                    <div class="mt-6 space-y-4">
                        @php
                            // One-time helpers for the rendering loop. Pull a stable "type" out of an
                            // OpenSSH public key line so we can render it as a chip; the comment (last
                            // whitespace-separated token) becomes the human-readable label, and the
                            // middle base64 blob is what gets monospace-displayed.
                            $sshTypeOf = static function (string $line): string {
                                $tok = strtok(trim($line), ' ');

                                return is_string($tok) ? $tok : 'ssh-?';
                            };
                            $sshCommentOf = static function (string $line): string {
                                $parts = preg_split('/\s+/', trim($line)) ?: [];

                                return count($parts) >= 3 ? implode(' ', array_slice($parts, 2)) : '';
                            };
                            $sshBodyOf = static function (string $line): string {
                                $parts = preg_split('/\s+/', trim($line)) ?: [];

                                return $parts[1] ?? trim($line);
                            };
                            // Recognize Dply's auto-managed keys so we can flag them clearly in the
                            // "kept" list — they aren't in the panel, but they're always on the
                            // server because the synchronizer re-injects them on every sync.
                            $operationalKeyLine = trim((string) ($server->openSshPublicKeyFromOperationalPrivate() ?? ''));
                            $recoveryKeyLine = trim((string) ($server->openSshPublicKeyFromRecoveryPrivate() ?? ''));
                            $isManagedKey = static function (string $line) use ($operationalKeyLine, $recoveryKeyLine): ?string {
                                if ($operationalKeyLine !== '' && trim($line) === $operationalKeyLine) {
                                    return 'operational';
                                }
                                if ($recoveryKeyLine !== '' && trim($line) === $recoveryKeyLine) {
                                    return 'recovery';
                                }

                                return null;
                            };
                        @endphp
                        @foreach ($diff_result as $user => $block)
                            @php
                                // Hide the root user from the workspace diff. Dply auto-manages a
                                // recovery key under root and we don't want to advertise that
                                // surface in the UI — the operator can still observe drift via
                                // audit events / direct server access if they need to.
                                if ($user === 'root') {
                                    continue;
                                }
                                $addedCount = count($block['added']);
                                $removedCount = count($block['removed']);
                                $keptLines = $block['kept'] ?? [];
                                $keptCount = count($keptLines);
                                $hasDrift = $addedCount > 0 || $removedCount > 0;
                            @endphp
                            <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/8 bg-brand-sand/20 px-4 py-3 sm:px-5">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10">
                                            <x-heroicon-m-user class="h-4 w-4" />
                                        </span>
                                        <h3 class="font-mono text-sm font-semibold text-brand-ink">{{ $user }}</h3>
                                        @if ($user === $server->ssh_user)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('login') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                        @if ($keptCount > 0)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 font-semibold text-brand-moss ring-1 ring-brand-ink/10" title="{{ __('Already on the server — Sync would not change these.') }}">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ trans_choice('{1} :count keeps|[2,*] :count keeps', $keptCount, ['count' => $keptCount]) }}
                                            </span>
                                        @endif
                                        @if ($hasDrift)
                                            @if ($addedCount > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200" title="{{ __('Will be added to authorized_keys on next sync') }}">
                                                    <x-heroicon-m-plus class="h-3 w-3" />
                                                    {{ trans_choice('{1} +:count to add|[2,*] +:count to add', $addedCount, ['count' => $addedCount]) }}
                                                </span>
                                            @endif
                                            @if ($removedCount > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700 ring-1 ring-rose-200" title="{{ __('Will be removed from authorized_keys on next sync') }}">
                                                    <x-heroicon-m-minus class="h-3 w-3" />
                                                    {{ trans_choice('{1} −:count to remove|[2,*] −:count to remove', $removedCount, ['count' => $removedCount]) }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('In sync') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    @if ($hasDrift)
                                        <div class="border-b border-brand-ink/8 bg-emerald-50/30 px-4 py-2 sm:px-5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-900">
                                                {{ __('Pending changes — Sync will apply these to the server') }}
                                            </p>
                                        </div>
                                        <div class="divide-y divide-brand-ink/8">
                                            @foreach ($block['added'] as $line)
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200" title="{{ __('On next sync, this key will be written to authorized_keys on the server.') }}">
                                                        <x-heroicon-m-plus class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $sshTypeOf($line) }}</span>
                                                            @if (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @endif
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('panel · not yet on server') }}</span>
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                            @foreach ($block['removed'] as $line)
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-700 ring-1 ring-rose-200" title="{{ __('On next sync, this key will be removed from authorized_keys on the server.') }}">
                                                        <x-heroicon-m-minus class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $sshTypeOf($line) }}</span>
                                                            @if (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @else
                                                                <span class="text-xs italic text-brand-mist">{{ __('untracked key on server') }}</span>
                                                            @endif
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('on server · not in panel') }}</span>
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($keptCount > 0)
                                        <div class="border-b border-t border-brand-ink/8 bg-brand-sand/15 px-4 py-2 sm:px-5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ __('Already on the server — no change') }}
                                            </p>
                                        </div>
                                        <div class="divide-y divide-brand-ink/8 bg-brand-sand/10">
                                            @foreach ($keptLines as $line)
                                                @php $managedKind = $isManagedKey($line); @endphp
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-brand-moss ring-1 ring-brand-ink/15" title="{{ __('Already on the server. Sync will not change this line.') }}">
                                                        <x-heroicon-m-check class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-white px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ $sshTypeOf($line) }}</span>
                                                            @if ($managedKind === 'operational')
                                                                <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                                    <x-heroicon-m-cog-6-tooth class="h-3 w-3" />
                                                                    {{ __('Dply operational') }}
                                                                </span>
                                                                <span class="text-xs text-brand-moss">{{ __('used by Dply to reach this server') }}</span>
                                                            @elseif (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @endif
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (! $hasDrift && $keptCount === 0)
                                        <div class="px-4 py-3 text-xs text-brand-moss sm:px-5">
                                            {{ __('No keys on the server for this user, and none in the panel either. Sync would write an empty authorized_keys.') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- The drift transcript renders in the workspace-level "console" banner above
                     the tabs (same one the sync flow uses). Keeping the diff structure here on
                     the tab and routing the transcript through the shared banner makes the UX
                     consistent across both flows. --}}
            </div>
