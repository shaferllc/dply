@php $referenceNested = (bool) ($isEmbedded ?? false); @endphp

<div @class(['space-y-6' => ! $referenceNested, 'min-w-0' => $referenceNested])>
    @include('livewire.sites.partials.pipeline._step-catalog')

    <details @class([
        'border-b border-brand-ink/10 px-5 py-5 sm:px-6' => $referenceNested,
        'rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8' => ! $referenceNested,
    ])>
        <summary class="cursor-pointer list-none">
            <h3 class="inline text-base font-semibold text-brand-ink">{{ __('Deploy script variables') }}</h3>
            <span class="ml-2 text-sm text-brand-moss">— {{ __('placeholders for custom steps, post-deploy commands, and hook scripts') }}</span>
        </summary>
        <dl class="mt-4 grid gap-px bg-brand-ink/10 md:grid-cols-2">
            @foreach ($deployVariableReference as $token => $description)
                <div class="bg-white px-4 py-3">
                    <dt class="font-mono text-sm text-brand-ink">{{ $token }}</dt>
                    <dd class="mt-1 text-sm text-brand-moss">{{ $description }}</dd>
                </div>
            @endforeach
        </dl>
    </details>

    <div @class([
        'border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6 space-y-3' => $referenceNested,
        'space-y-3' => ! $referenceNested,
    ])>
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
