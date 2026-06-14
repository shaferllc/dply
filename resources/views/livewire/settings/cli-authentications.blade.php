<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('CLI'), 'icon' => 'command-line'],
        ]" />
    @endpush

    @php
        $sessionCount = $cliTokens->count();
        $orgCount = $organizations->count();
        $lastUsed = $cliTokens->pluck('last_used_at')->filter()->sort()->last();
    @endphp

    {{-- Hero card: positioning + at-a-glance counts (mirrors the
         notification-channels header). --}}
    <x-hero-card
        :eyebrow="__('Command line')"
        :title="__('CLI')"
        :description="__('Install the dply CLI, sign in once with device-flow login, and manage every CLI session tied to your organizations from here.')"
        icon="command-line"
    >
        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sessions') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $sessionCount }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('session|sessions', $sessionCount) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Active devices') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organizations') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgCount }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('available|available', $orgCount) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('You administer') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last used') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-sm font-semibold text-brand-ink">{{ $lastUsed ? $lastUsed->diffForHumans() : '—' }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Most recent sign-in') }}</p>
                </div>
            </dl>
        </x-slot:stats>
    </x-hero-card>

    @if ($organizations->isEmpty())
        <section class="dply-card mt-6 overflow-hidden p-6 sm:p-8">
            <p class="text-sm text-brand-moss">{{ __('Org admin access is required to manage CLI authentications.') }}</p>
        </section>
    @else
        <div class="mt-6 space-y-6">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Install') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Get the dply CLI') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Requires Node 18+. Run `dply login` — your browser opens here, you approve once, and the terminal drops into `dply shell`. Press Enter for numbered menus, or type commands directly.') }}
                        </p>
                    </div>
                </div>
                <div class="space-y-4 px-6 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('1. Install') }}</p>
                        @php $installUrl = route('cli.install'); @endphp
                        <x-cli-snippet class="mt-2" :commands="[
                            ['label' => '', 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login'],
                        ]" />
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('The CLI is hosted by this dply instance — not npm. The script downloads /cli/dply-cli.tgz and installs it globally. Node 18+ required.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2. Sign in') }}</p>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('If you used `--login` above, you are already authenticated. Otherwise run:') }}
                        </p>
                        <x-cli-snippet class="mt-2" :commands="[
                            ['label' => '', 'command' => 'dply login --base-url '.$appUrl],
                        ]" />
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('Need more scopes later? Run `dply auth refresh` (or `dply refresh`) — same browser approval, new token on that machine.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('3. Verify') }}</p>
                        <x-cli-snippet class="mt-2" :commands="[
                            ['label' => __('Account'), 'command' => 'dply account show'],
                            ['label' => __('Menu'),    'command' => 'dply menu'],
                            ['label' => __('Servers'), 'command' => 'dply server list'],
                            ['label' => __('Sites'),   'command' => 'dply site list'],
                        ]" />
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('4. Deploy a BYO site from your repo') }}</p>
                        <x-cli-snippet class="mt-2" :commands="[
                            ['label' => __('Link'),   'command' => 'dply link'],
                            ['label' => __('Deploy'), 'command' => 'dply deploy --follow'],
                            ['label' => __('Status'), 'command' => 'dply site status'],
                            ['label' => __('Logs'),   'command' => 'dply site logs --follow'],
                        ]" />
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('`dply link` opens a picker (BYO + Edge). Edge: `dply edge status --wait` or `dply deploy --wait`. Server SSH: `dply server run --server <id> <command>` needs `commands.run`. Firewall: `dply server firewall show` needs `network.read` — run `dply auth refresh` if scopes are missing.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('5. GitHub Actions (BYO deploy)') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
      - run: curl -fsSL {{ $installUrl }} | bash -s -- --no-shell
      - run: dply login --token "$@{{ secrets.DPLY_TOKEN }}" --no-shell
      - run: dply deploy --sync --wait --idempotency-key "$@{{ github.sha }}"</code></pre>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('Create an org API token with sites.deploy. Link the site once locally (`dply link --byo …`) and commit `.dply/site.json`, or pass `--site` in CI.') }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-code-bracket-square class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Configure') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repo config: dply.yaml or dply.json') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Drop a `dply.yaml`, `dply.yml`, or `dply.json` at your repo root. dply reads it on every deploy — build overrides, redirects, rewrites, header rules, and env declarations. YAML and JSON are interchangeable; pick whichever your team prefers.') }}
                        </p>
                    </div>
                </div>
                <div class="space-y-4 px-6 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('YAML — dply.yaml') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>@verbatim build:
  command: npm run build
  output: dist
  node: "20"

redirects:
  - from: /old/*
    to: /new/:splat
    status: 301

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"

env:
  public:
    NODE_VERSION: "20"
  secret:
    - DATABASE_URL@endverbatim</code></pre>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('JSON — dply.json (equivalent)') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>@verbatim{
  "build": { "command": "npm run build", "output": "dist", "node": "20" },
  "redirects": [
    { "from": "/old/*", "to": "/new/:splat", "status": 301 }
  ],
  "headers": [
    { "for": "/assets/*", "values": { "Cache-Control": "public, max-age=31536000, immutable" } }
  ],
  "env": {
    "public": { "NODE_VERSION": "20" },
    "secret": ["DATABASE_URL"]
  }
}@endverbatim</code></pre>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Validate before you push') }}</p>
                        <x-cli-snippet class="mt-2" :commands="[
                            ['label' => __('Lint cwd'),  'command' => 'dply lint'],
                            ['label' => __('Lint file'), 'command' => 'dply lint --path dply.json'],
                        ]" />
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('`dply lint` runs the same rules the build uses on deploy — fatal parse errors fail the build, and malformed rules are dropped with a warning. Secrets stay out of the file: list secret names under `env.secret` and set their values in the dashboard.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('All supported keys') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Every section is optional. Keys not listed here are ignored. The examples above use YAML, but each key works identically in JSON.') }}
                        </p>
                        <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-brand-sand/30 text-brand-mist">
                                    <tr>
                                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Key') }}</th>
                                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('What it does') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10">
                                    @foreach ([
                                        ['build', __('Build overrides: `command`, `output`, `root` (monorepo subdir), `node` version, and `env_files` (repo-relative .env paths merged into the build).')],
                                        ['redirects', __('List of `{from, to, status}`. Status must be 301, 302, 303, 307, or 308 (defaults to 301).')],
                                        ['rewrites', __('List of `{from, to}` — proxy a path pattern to another path or URL without changing the address bar.')],
                                        ['headers', __('List of `{for, values}` — attach response header name/value pairs to paths matching the `for` glob.')],
                                        ['env', __('`public:` map of safe-to-commit NAME: value pairs, plus `secret:` — a list of names only (values are set in the dashboard).')],
                                        ['bindings', __('Cloudflare bindings: `kv`, `r2`, `d1`, `queues` maps of ALL_CAPS name → resource id or title. Co-equal with wrangler.toml.')],
                                        ['origin', __('Origin proxy: `url`, `routes` (path patterns to proxy), and `failover_html` shown when the origin is unreachable.')],
                                        ['domains', __('List of hostnames to attach on deploy. Attach-only — removing a name never detaches (detach via dashboard/API).')],
                                        ['crons', __('List of `{schedule}` (5-field cron). Up to 5 per site; exports `scheduled` from your middleware module.')],
                                        ['firewall', __('`country_mode` (off / allow / block) plus a `countries` list of ISO 3166 alpha-2 codes.')],
                                        ['images', __('`allowed_hosts` — hostnames the image-resizing proxy may fetch from. (Signing secret is set in the dashboard.)')],
                                        ['error_pages', __('Custom 404/500 HTML via `html_404`/`html_500` (inline) or `html_404_path`/`html_500_path` (repo-relative files).')],
                                        ['maintenance', __('`enabled` (bool) plus `html` or `html_path` — serves 503 + your page on every request when on.')],
                                        ['previews', __('Preview gating: `enabled`, `pr_only`, `branches`, `exclude_branches`, and `protection` (mode + allowed_emails).')],
                                        ['comment_widget', __('`enabled` (bool) — toggle the preview-deploy comment widget. Token/api_base are generated server-side.')],
                                    ] as [$key, $desc])
                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2 align-top font-mono text-brand-ink">{{ $key }}</td>
                                            <td class="px-3 py-2 leading-relaxed text-brand-moss">{{ $desc }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sessions') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('CLI authentications') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Tokens from device-flow login (“:name”). Revoke to sign a machine out immediately.', ['name' => $cliTokenName]) }}
                            </p>
                        </div>
                    </div>
                    @if ($organizations->count() > 1)
                        <select
                            wire:model.live="organization_id"
                            class="block min-w-[12rem] rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        >
                            @foreach ($organizations as $org)
                                <option value="{{ $org->id }}">{{ $org->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                @if ($cliTokens->isEmpty())
                    <div class="px-6 py-10 text-center sm:px-7">
                        <p class="text-sm font-medium text-brand-ink">{{ __('No CLI sessions yet') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Run `dply login` from a terminal and approve the device to create the first session.') }}
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($cliTokens as $token)
                            <li wire:key="cli-token-{{ $token->id }}" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm text-brand-ink">{{ $token->token_prefix }}…</p>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        {{ $token->user?->email ?? __('Unknown') }}
                                        · {{ $token->created_at?->diffForHumans() }}
                                        @if ($token->last_used_at)
                                            · {{ __('Last used :time', ['time' => $token->last_used_at->diffForHumans()]) }}
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('revokeCliToken', [@js((string) $token->id)], @js(__('Revoke this CLI session?')), @js(__('That machine loses API access immediately. Re-run `dply login` to reconnect.')), @js(__('Revoke session')), true)"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100"
                                >
                                    <x-heroicon-o-x-circle class="h-4 w-4" />
                                    {{ __('Revoke') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
