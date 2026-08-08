@php $referenceNested = (bool) ($isEmbedded ?? false); @endphp

<div class="min-w-0">
    @include('livewire.sites.partials.pipeline._step-catalog')

    <details class="group border-b border-brand-ink/10">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-2.5 marker:content-none sm:px-6">
            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-open:rotate-90" aria-hidden="true" />
            <span class="min-w-0">
                <span class="block text-xs font-semibold text-brand-ink">{{ __('Deploy script variables') }}</span>
                <span class="mt-0.5 block text-[11px] text-brand-moss">{{ __('Placeholders for custom steps, post-deploy commands, and hook scripts.') }}</span>
            </span>
        </summary>
        <dl class="grid gap-px border-t border-brand-ink/10 bg-brand-ink/10 md:grid-cols-2">
            @foreach ($deployVariableReference as $token => $description)
                <div class="bg-white px-4 py-2 sm:px-5">
                    <dt class="font-mono text-xs text-brand-ink">{{ $token }}</dt>
                    <dd class="mt-0.5 text-[11px] text-brand-moss">{{ $description }}</dd>
                </div>
            @endforeach
        </dl>
    </details>

    <div class="space-y-2.5 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
        <x-cli-snippet
            :summary="__('dply CLI (from your laptop)')"
            :intro="__('Run `dply link --byo :id` once in your repo root, commit `.dply/site.json`, then deploy with bare `dply deploy`. Re-login with `dply auth refresh` if scopes are missing.', ['id' => $site->id])"
            :commands="[
                ['label' => __('Link this repo'), 'command' => 'dply link --byo '.$site->id],
                ['label' => __('Deploy (linked repo)'), 'command' => 'dply deploy --follow'],
                ['label' => __('Deploy this site'), 'command' => 'dply site deploy --site '.$site->id.' --follow'],
                ['label' => __('Tail deploy logs'), 'command' => 'dply site logs --site '.$site->id.' --follow'],
                ['label' => __('Site status'), 'command' => 'dply site status --site '.$site->id],
            ]"
        />

        <x-cli-snippet
            :summary="__('Artisan (on the server)')"
            :commands="[
                ['label' => __('Trigger deploy'), 'command' => 'dply sites:deploy '.$site->slug],
                ['label' => __('Abort running deploy'), 'command' => 'dply sites:deploy:abort '.$site->slug],
                ['label' => __('Run a single phase'), 'command' => 'dply sites:deploy:phase '.$site->slug.' build'],
                ['label' => __('Recent deploy history'), 'command' => 'dply sites:deployments '.$site->slug],
                ['label' => __('Inspect a deploy'), 'command' => 'dply sites:deployment DEPLOYMENT_ID --output'],
            ]"
        />
    </div>
</div>
