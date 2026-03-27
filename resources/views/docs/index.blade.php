<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">Docs</h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <p class="text-slate-600 mb-8">Short guides to get started with dply.</p>
            <ul class="space-y-4">
                <li>
                    <a href="{{ route('docs.connect-provider') }}" class="block p-4 rounded-lg border border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 transition">
                        <span class="font-medium text-slate-900">Connect a cloud provider</span>
                        <p class="text-sm text-slate-500 mt-1">Get an API token from DigitalOcean or Hetzner and add it in Credentials.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.create-first-server') }}" class="block p-4 rounded-lg border border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 transition">
                        <span class="font-medium text-slate-900">Create your first server</span>
                        <p class="text-sm text-slate-500 mt-1">Choose provider, region, size, optional setup script; then deploy.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.org-roles-and-limits') }}" class="block p-4 rounded-lg border border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 transition">
                        <span class="font-medium text-slate-900">Roles &amp; plan limits</span>
                        <p class="text-sm text-slate-500 mt-1">Owner, admin, member, deployer; server and site caps on Free vs Pro.</p>
                    </a>
                </li>
                <li>
                    <a href="{{ route('docs.source-control') }}" class="block p-4 rounded-lg border border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 transition">
                        <span class="font-medium text-slate-900">Source control &amp; deploy flow</span>
                        <p class="text-sm text-slate-500 mt-1">Repos, webhooks, and how deployments run end to end.</p>
                    </a>
                </li>
                @if (Route::has('docs.api'))
                    <li>
                        <a href="{{ route('docs.api') }}" class="block p-4 rounded-lg border border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 transition">
                            <span class="font-medium text-slate-900">API</span>
                            <p class="text-sm text-slate-500 mt-1">Use the API for CI/CD: list servers, trigger deploy.</p>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</x-app-layout>
