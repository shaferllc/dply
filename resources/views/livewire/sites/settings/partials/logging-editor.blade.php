{{-- Managed logging editor (Phase 3). Assembles the full config/logging.php
     spec dply owns: channels + default + stack + deprecations, with a preview of
     the generated file. Driven by ManagesSiteLogging. --}}
@php
    use App\Services\Logging\LoggingChannelCatalog;
    $this->hydrateLoggingSpec();
    $spec = $this->loggingSpec;
    $channels = $spec['channels'] ?? [];
    $stack = $spec['stack'] ?? [];
    $catalog = LoggingChannelCatalog::types();
    $levels = LoggingChannelCatalog::LEVELS;
    $typeOptions = $this->loggingChannelTypeOptions();
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8 sm:py-6">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-violet-50 text-violet-700 ring-violet-200">
                <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Logging') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Logging configuration') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('dply generates and owns config/logging.php from this configuration. It is validated and written into each release at deploy; your repo copy is never touched in git.') }}
                </p>
            </div>
        </div>
    </div>

    <div class="space-y-5 px-6 py-5 sm:px-8">
        {{-- Channels --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Channels') }}</p>
                <div x-data="{ open: false }" class="relative">
                    <button type="button" x-on:click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                        <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add channel') }}
                    </button>
                    <div x-show="open" x-on:click.outside="open = false" x-transition class="absolute right-0 z-10 mt-1 w-56 overflow-hidden rounded-lg border border-brand-ink/10 bg-white py-1 shadow-lg" style="display:none">
                        @foreach ($typeOptions as $opt)
                            <button type="button" wire:click="addLoggingChannel('{{ $opt['type'] }}')" x-on:click="open = false" class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                <span>{{ $opt['label'] }}</span>
                                @if ($opt['escape'])<span class="text-[10px] text-amber-600">{{ __('advanced') }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if (count($channels) === 0)
                <p class="rounded-lg border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs italic text-brand-mist">{{ __('No channels yet — add one to begin.') }}</p>
            @endif

            @foreach ($channels as $i => $ch)
                @php
                    $type = $ch['type'] ?? '';
                    $meta = $catalog[$type] ?? null;
                    $name = $ch['name'] ?? '';
                    $isDefault = ($spec['default'] ?? null) === $name;
                    $inStack = in_array($name, $stack, true);
                @endphp
                @continue($meta === null)
                <div wire:key="logch-{{ $name }}" class="rounded-xl border border-brand-ink/10 bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm font-semibold text-brand-ink">{{ $name }}</span>
                            <span class="rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $meta['label'] }}</span>
                            @if ($meta['is_escape_hatch'] ?? false)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('unvalidated') }}</span>
                            @endif
                        </div>
                        <button type="button" wire:click="removeLoggingChannel('{{ $name }}')" class="text-brand-mist hover:text-rose-600" title="{{ __('Remove channel') }}">
                            <x-heroicon-o-trash class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @if ($meta['supports_level'] ?? false)
                            <label class="block">
                                <span class="text-[11px] font-medium text-brand-moss">{{ __('Level') }}</span>
                                <select wire:model="loggingSpec.channels.{{ $i }}.level" class="dply-input mt-1 w-full text-sm">
                                    @foreach ($levels as $lvl)<option value="{{ $lvl }}">{{ $lvl }}</option>@endforeach
                                </select>
                            </label>
                        @endif
                        @if ($meta['supports_format'] ?? false)
                            <label class="block">
                                <span class="text-[11px] font-medium text-brand-moss">{{ __('Format') }}</span>
                                <select wire:model="loggingSpec.channels.{{ $i }}.format" class="dply-input mt-1 w-full text-sm">
                                    <option value="line">{{ __('Line (human-readable)') }}</option>
                                    <option value="json">{{ __('JSON (structured)') }}</option>
                                </select>
                            </label>
                        @endif

                        @foreach (($meta['fields'] ?? []) as $field)
                            @php
                                $fkey = $field['key'];
                                $isSecret = (bool) ($field['secret'] ?? false);
                                $isSystem = (bool) ($field['system'] ?? false);
                            @endphp
                            @continue($isSystem)
                            @if ($isSecret)
                                <label class="block">
                                    <span class="text-[11px] font-medium text-brand-moss">{{ $field['label'] }}</span>
                                    <input type="password" autocomplete="new-password" wire:model="loggingSecrets.{{ $name }}.{{ $fkey }}" placeholder="{{ __('•••• (leave blank to keep)') }}" class="dply-input mt-1 w-full text-sm" />
                                </label>
                            @elseif (in_array(($field['kind'] ?? 'text'), ['keyval', 'classlist'], true))
                                {{-- custom-channel free-text inputs, parsed on save --}}
                                <label class="block sm:col-span-2">
                                    <span class="text-[11px] font-medium text-brand-moss">{{ $field['label'] }}</span>
                                    <textarea rows="2" wire:model="loggingSpec.channels.{{ $i }}.{{ $fkey === 'handler_with' ? 'handler_with_text' : ($fkey === 'processors' ? 'processors_text' : $fkey) }}" placeholder="{{ ($field['kind'] === 'keyval') ? 'key: value' : 'Fully\\Qualified\\ClassName' }}" class="dply-input mt-1 w-full font-mono text-xs"></textarea>
                                </label>
                            @else
                                <label class="block">
                                    <span class="text-[11px] font-medium text-brand-moss">{{ $field['label'] }}</span>
                                    <input type="{{ ($field['kind'] ?? 'text') === 'number' ? 'number' : 'text' }}" wire:model="loggingSpec.channels.{{ $i }}.{{ $fkey }}" class="dply-input mt-1 w-full text-sm" />
                                    @if (! empty($field['help']))<span class="mt-1 block text-[10px] leading-snug text-brand-mist">{{ $field['help'] }}</span>@endif
                                </label>
                            @endif
                        @endforeach
                    </div>

                    @if (isset(LoggingChannelCatalog::TRANSPORT_PACKAGES[$type]))
                        <p class="mt-2 rounded-md bg-amber-50 px-2.5 py-1.5 text-[10px] leading-snug text-amber-700">
                            {{ __('Requires your app to composer require :pkg — a missing package fails the deploy probe.', ['pkg' => LoggingChannelCatalog::TRANSPORT_PACKAGES[$type]]) }}
                        </p>
                    @endif

                    <div class="mt-3 flex items-center gap-4 border-t border-brand-ink/5 pt-3 text-xs">
                        <label class="inline-flex items-center gap-1.5">
                            <input type="radio" wire:click="setLoggingDefault('{{ $name }}')" @checked($isDefault) class="text-brand-forest" />
                            <span class="text-brand-moss">{{ __('Default channel') }}</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5">
                            <input type="checkbox" wire:click="toggleLoggingStackMember('{{ $name }}')" @checked($inStack) class="rounded text-brand-forest" />
                            <span class="text-brand-moss">{{ __('In stack') }}</span>
                        </label>
                        <button type="button" wire:click="testLoggingChannel('{{ $name }}')" class="ml-auto inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Emit a test record (uses the last deployed config)') }}">
                            <x-heroicon-o-paper-airplane class="h-4 w-4" /> {{ __('Test') }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Composition: stack-as-default + deprecations --}}
        <div class="grid grid-cols-1 gap-4 rounded-xl bg-brand-cream/40 p-4 sm:grid-cols-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Default target') }}</p>
                <label class="mt-2 inline-flex items-center gap-1.5 text-xs">
                    <input type="radio" wire:click="setLoggingDefault('stack')" @checked(($spec['default'] ?? null) === 'stack') @disabled(count($stack) === 0) class="text-brand-forest" />
                    <span class="text-brand-moss">{{ __('Use the stack (:members)', ['members' => count($stack) ? implode(', ', $stack) : __('empty')]) }}</span>
                </label>
                <p class="mt-1 text-[10px] text-brand-mist">{{ __('Or pick a single default on a channel above.') }}</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deprecations') }}</p>
                <div class="mt-2 flex items-center gap-3">
                    <select wire:model="loggingSpec.deprecations.channel" class="dply-input text-xs">
                        <option value="null">{{ __('null (ignore)') }}</option>
                        @foreach ($channels as $ch)<option value="{{ $ch['name'] }}">{{ $ch['name'] }}</option>@endforeach
                    </select>
                    <label class="inline-flex items-center gap-1.5 text-xs">
                        <input type="checkbox" wire:model="loggingSpec.deprecations.trace" class="rounded text-brand-forest" />
                        <span class="text-brand-moss">{{ __('Trace') }}</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-between gap-3">
            <button type="button" wire:click="previewLoggingConfig" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-eye class="h-4 w-4" /> {{ __('Preview generated file') }}
            </button>
            <button type="button" wire:click="saveLoggingSpec" wire:loading.attr="disabled" wire:target="saveLoggingSpec" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                <x-heroicon-o-check class="h-4 w-4" wire:loading.remove wire:target="saveLoggingSpec" />
                <x-spinner wire:loading wire:target="saveLoggingSpec" variant="cream" size="sm" />
                <span wire:loading.remove wire:target="saveLoggingSpec">{{ __('Save logging') }}</span>
                <span wire:loading wire:target="saveLoggingSpec">{{ __('Saving…') }}</span>
            </button>
        </div>

        @if ($showLoggingPreview)
            <div class="rounded-xl border border-brand-ink/10 bg-brand-ink/5">
                <div class="flex items-center justify-between border-b border-brand-ink/10 px-4 py-2">
                    <p class="font-mono text-[11px] text-brand-moss">config/logging.php</p>
                    <button type="button" wire:click="$set('showLoggingPreview', false)" class="text-brand-mist hover:text-brand-ink"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
                </div>
                <pre class="max-h-96 overflow-auto px-4 py-3 text-[11px] leading-relaxed text-brand-ink"><code>{{ $loggingPreviewContent }}</code></pre>
            </div>
        @endif
    </div>
</section>
