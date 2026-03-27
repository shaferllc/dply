<div>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-slate-900">Account &amp; organization</h1>
        <p class="mt-2 text-sm text-slate-600 max-w-2xl">
            Manage your profile, security, organizations, billing, and integrations. Use the sidebar to jump to a section.
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <a href="{{ route('profile.edit') }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
            <h2 class="font-medium text-slate-900">Profile</h2>
            <p class="mt-1 text-sm text-slate-500">Name, email, password, and account deletion.</p>
        </a>
        <a href="{{ route('two-factor.setup') }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
            <h2 class="font-medium text-slate-900">Security</h2>
            <p class="mt-1 text-sm text-slate-500">Two-factor authentication for your Dply login.</p>
        </a>
        <a href="{{ route('organizations.index') }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
            <h2 class="font-medium text-slate-900">Organizations</h2>
            <p class="mt-1 text-sm text-slate-500">Members, API tokens, outbound webhooks, and plan usage.</p>
        </a>
        <a href="{{ route('docs.source-control') }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
            <h2 class="font-medium text-slate-900">Source control</h2>
            <p class="mt-1 text-sm text-slate-500">Repos, webhooks, and how deploys are triggered per site.</p>
        </a>
        @if (auth()->user()->currentOrganization())
            @php $org = auth()->user()->currentOrganization(); @endphp
            <a href="{{ route('credentials.index') }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
                <h2 class="font-medium text-slate-900">Provider credentials</h2>
                <p class="mt-1 text-sm text-slate-500">Cloud API tokens for provisioning servers.</p>
            </a>
            @if ($org->hasAdminAccess(auth()->user()))
                <a href="{{ route('billing.show', $org) }}" class="block rounded-lg border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 transition">
                    <h2 class="font-medium text-slate-900">Billing &amp; invoices</h2>
                    <p class="mt-1 text-sm text-slate-500">Subscription, usage limits, and Stripe invoices.</p>
                </a>
            @endif
        @endif
    </div>

    <section class="mt-10 rounded-lg border border-slate-200 bg-slate-50/80 p-6">
        <h2 class="text-sm font-semibold text-slate-800">What Dply does not manage</h2>
        <ul class="mt-3 space-y-2 text-sm text-slate-600 list-disc list-inside">
            <li><span class="font-medium text-slate-700">Backups</span> — configure snapshots or backup tools on your servers or with your cloud provider.</li>
            <li><span class="font-medium text-slate-700">Web server templates</span> — Nginx and PHP settings are edited per site in Dply.</li>
            <li><span class="font-medium text-slate-700">SSH keys for servers</span> — add deploy and personal keys on each server’s page.</li>
        </ul>
    </section>
</div>
