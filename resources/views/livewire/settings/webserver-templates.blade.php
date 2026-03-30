<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" :section="$orgShellSection ?? 'webserver'">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Webserver templates') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-brand-moss">
                    {{ __('Optional Nginx :code blocks for custom vhosts. Placeholders are replaced when you apply a template to a site (future) or when testing.', ['code' => 'server']) }}
                </p>
            </div>

    @if ($testMessage)
        <div
            @class([
                'mb-6 rounded-lg border px-4 py-3 text-sm',
                'border-green-200 bg-green-50 text-green-900' => $testOk,
                'border-red-200 bg-red-50 text-red-900' => ! $testOk,
            ])
            role="status"
        >
            <p class="font-medium">{{ $testOk ? __('Syntax check') : __('Syntax check failed') }}</p>
            <pre class="mt-2 whitespace-pre-wrap font-mono text-xs opacity-90">{{ $testMessage }}</pre>
        </div>
    @endif

    <div class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden mb-8">
        <div class="border-b border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
            <h2 class="text-lg font-semibold text-brand-ink">
                {{ $editingId ? __('Edit webserver template') : __('Create webserver template') }}
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Use placeholders in the template body. Applying templates to live sites will be wired from the site screen.') }}
            </p>
        </div>
        <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
            <div class="lg:col-span-5 mb-8 lg:mb-0">
                <p class="text-sm font-medium text-brand-ink">{{ __('Variables') }}</p>
                <ul class="mt-3 space-y-2 text-sm text-brand-moss">
                    @foreach ($placeholders as $key => $description)
                        <li>
                            <code class="rounded bg-brand-sand/80 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ '{'.$key.'}' }}</code>
                            — {{ __($description) }}
                        </li>
                    @endforeach
                </ul>
                @php $banner = config('webserver_templates.required_banner_line'); @endphp
                @if ($banner)
                    <p class="mt-4 text-xs text-brand-mist">{{ __('Required first line: :line', ['line' => $banner]) }}</p>
                @endif
            </div>
            <div class="lg:col-span-7 space-y-4 min-w-0">
                @if (! $canManage)
                    <p class="text-sm text-brand-moss">{{ __('Only organization admins can create or edit templates.') }}</p>
                @else
                    <div>
                        <label for="tpl-label" class="block text-sm font-medium text-brand-ink">{{ __('Label') }}</label>
                        <input
                            id="tpl-label"
                            type="text"
                            wire:model="label"
                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            placeholder="{{ __('Enter a label to recognize your template') }}"
                        />
                        @error('label') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tpl-content" class="block text-sm font-medium text-brand-ink">{{ __('Template content') }}</label>
                        <textarea
                            id="tpl-content"
                            wire:model="content"
                            rows="18"
                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            spellcheck="false"
                        ></textarea>
                        @error('content') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            wire:click="testDraft"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-lg border border-brand-mist bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            {{ __('Test template') }}
                        </button>
                        @if ($editingId)
                            <button type="button" wire:click="cancelEdit" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                {{ __('Cancel edit') }}
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90"
                        >
                            {{ $editingId ? __('Save changes') : __('Create') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($templates->isEmpty())
        <p class="text-sm text-brand-moss">{{ __('No templates yet.') }}</p>
    @else
        <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-mist mb-4">{{ __('Saved templates') }}</h2>
        <ul class="space-y-4">
            @foreach ($templates as $template)
                <li class="rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="font-medium text-brand-ink">{{ $template->label }}</h3>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('Created :time', ['time' => $template->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s')]) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 shrink-0">
                            @if ($canManage)
                                <button
                                    type="button"
                                    wire:click="startEdit({{ $template->id }})"
                                    class="inline-flex items-center rounded-lg border border-brand-mist bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    {{ __('Edit') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="testSaved({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-lg border border-brand-mist bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Test template') }}
                            </button>
                            @if ($canManage)
                                <button
                                    type="button"
                                    wire:click="delete({{ $template->id }})"
                                    wire:confirm="{{ __('Delete this template?') }}"
                                    class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-100"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.134-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.067-2.09 1.134-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                    {{ __('Delete') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
        </x-organization-shell>
    </div>
</div>
