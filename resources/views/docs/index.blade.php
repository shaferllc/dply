<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-brand-ink leading-tight">Docs</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <p class="text-brand-moss mb-8">Short guides to get started with dply.</p>
            <ul class="space-y-4">
                <li>
                    <a href="{{ route('docs.connect-provider') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition">
                        <span class="font-medium text-brand-ink">Connect a cloud provider</span>
                        <p class="text-sm text-brand-mist mt-1">Get an API token from DigitalOcean or Hetzner and add it under Server providers.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.create-first-server') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition">
                        <span class="font-medium text-brand-ink">Create your first server</span>
                        <p class="text-sm text-brand-mist mt-1">Choose provider, region, size, optional setup script; then deploy.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition">
                        <span class="font-medium text-brand-ink">Roles, trial limits, and Pro billing</span>
                        <p class="text-sm text-brand-mist mt-1">Owner, admin, member, deployer; organization-wide trial caps and what changes on Pro.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.markdown', ['slug' => 'source-control']) }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition">
                        <span class="font-medium text-brand-ink">Source control &amp; deploy flow</span>
                        <p class="text-sm text-brand-mist mt-1">Repos, webhooks, and how deployments run end to end.</p>
                    </a>
                </li>
                @if (Route::has('docs.api'))
                    <li>
                        <a href="{{ route('docs.api') }}" class="block p-4 rounded-lg border border-brand-ink/10 bg-white hover:border-brand-mist/40 hover:bg-slate-50 transition">
                            <span class="font-medium text-brand-ink">API</span>
                            <p class="text-sm text-brand-mist mt-1">Use the API for CI/CD: list servers, trigger deploy.</p>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</x-app-layout>
