export const meta = {
  name: 'hero-card-rollout',
  description: 'Roll out the shared <x-hero-card> header across server/project layouts and site pages',
  phases: [
    { title: 'Layouts', detail: 'Swap page-header → hero-card in server & project workspace layouts' },
    { title: 'Sites', detail: 'Insert a hero-card header into each real site page' },
    { title: 'Verify', detail: 'Re-check each edited site page for single-root + blade validity' },
  ],
}

// ---------------------------------------------------------------------------
// Shared context handed to every agent so edits stay consistent.
// ---------------------------------------------------------------------------
const COMPONENT_DOC = `
The reusable component lives at resources/views/components/hero-card.blade.php. API:

  <x-hero-card
      :eyebrow="__('Settings')"      {{-- small uppercase label; optional --}}
      :title="__('Profile')"          {{-- required --}}
      :description="__('…')"          {{-- optional supporting paragraph --}}
      icon="cog-6-tooth"              {{-- Heroicon slug WITHOUT the heroicon-o- prefix; optional --}}
      tone="auto"                     {{-- icon badge tone; default 'auto' (deterministic color) --}}
      iconSize="md"                   {{-- 'default' | 'md' | 'lg'; default 'md' --}}
  >
      <x-slot:topAction> … </x-slot:topAction>   {{-- optional top-RIGHT action (e.g. an "Add" button / docs link) --}}

      {{-- default slot = the row of action PILLS (use <x-outline-link>) --}}
      <x-outline-link href="…" wire:navigate>…</x-outline-link>

      <x-slot:stats>                  {{-- optional right-hand stat tiles --}}
          <dl class="grid grid-cols-3 gap-2">
              <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                  <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">LABEL</dt>
                  <dd class="mt-1 text-sm font-semibold text-brand-ink">VALUE</dd>
                  <p class="mt-1 text-[11px] text-brand-mist">caption</p>
              </div>
              {{-- repeat tiles --}}
          </dl>
      </x-slot:stats>

      {{-- Use a 'leading' slot instead of the 'icon' prop ONLY when a fully custom badge is needed. --}}
  </x-hero-card>

Brand utility classes already exist: text-brand-ink (headings), text-brand-moss (body),
text-brand-sage (eyebrow), text-brand-mist (muted), dply-card (card surface).
All user-facing strings MUST be wrapped in __('…') for translation.
`.trim()

const SINGLE_ROOT_RULE = `
CRITICAL — Livewire single-root rule: a full-page Livewire view must have exactly ONE
top-level root element, or it throws "Snapshot missing / Component not found". The page
already has a root <div>. Insert the <x-hero-card> as the FIRST child INSIDE that root
(after any @push('breadcrumbs')/@push blocks and @php blocks, before the existing visible
header/content). NEVER add it as a second top-level sibling element.
`.trim()

// ---------------------------------------------------------------------------
// Phase 1 — shared workspace layouts (server + project)
// ---------------------------------------------------------------------------
phase('Layouts')

const LAYOUT_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['file', 'changed', 'summary'],
  properties: {
    file: { type: 'string' },
    changed: { type: 'boolean' },
    summary: { type: 'string', description: 'One line on what was changed or why not.' },
  },
}

const layoutResults = await parallel([
  () => agent(`Edit the Blade file resources/views/components/server-workspace-layout.blade.php.

GOAL: replace its current <x-page-header ...>...</x-page-header> block (around lines 114-127)
with the shared <x-hero-card> component, so every server workspace page gets the richer hero
header. Keep EVERYTHING else in the file unchanged: the @props, the @php breadcrumb logic, the
<x-slot:breadcrumb> block, the <x-server-workspace-shell> wrapper, the content {{ $slot }}, and
{{ $modals }}.

Requirements:
- title: use the SAME expression the page-header used: \$contextSite ? \$title.' — '.\$contextSite->name : \$title
- description: pass through :description="\$description"
- icon: use icon="server-stack" (server theme).
- Map the existing \$headerLeading slot (if set) to the hero-card's <x-slot:leading> slot instead of page-header's leading.
- Add server-scoped stat tiles in <x-slot:stats> using the \$server model, DEFENSIVELY (null-safe):
  a "Status" tile (\$server->status), a "Region" tile (\$server->region ?? '—'), and an "IP" tile
  (\$server->public_ip_address ?? \$server->ip_address ?? '—'). Use ucfirst/str_replace for nice labels;
  if you are unsure a property exists, guard with ?? '—' and DO NOT invent accessors. If you cannot
  confidently produce safe stat tiles, OMIT the stats slot entirely (intro-only hero is fine).
- The hero-card must sit in the SAME position the page-header occupied (after the breadcrumb slot,
  before <div class="mt-6 space-y-8 ...">{{ \$slot }}</div>).
- Preserve the toolbar/compact intent loosely — hero-card has no toolbar prop, so just drop those;
  do not error on their absence.

Read the file first. Make the edit. Confirm the file still has balanced Blade directives.

${COMPONENT_DOC}`, { label: 'layout:server', phase: 'Layouts', schema: LAYOUT_SCHEMA }),

  () => agent(`Edit the Blade file resources/views/components/project-workspace-layout.blade.php.

GOAL: replace its current <x-page-header ...>...</x-page-header> block (around lines 64-73) with
the shared <x-hero-card> component. Keep everything else unchanged (the @props, the per-section
\$current title/description map, the wrapper, and the content slot).

Requirements:
- title: :title="\$current['label']"
- description: :description="\$current['description']"
- icon: use icon="rectangle-group" (project theme).
- Map any existing leading/headerLeading slot to <x-slot:leading> if present; otherwise no leading slot.
- Optionally add project-scoped stat tiles in <x-slot:stats> ONLY if the component clearly has safe,
  obvious data available (e.g. counts already computed in the file). If not obvious, OMIT stats.
- Keep the hero-card in the same position the page-header occupied.

Read the file first, make the edit, confirm balanced Blade directives.

${COMPONENT_DOC}`, { label: 'layout:project', phase: 'Layouts', schema: LAYOUT_SCHEMA }),
])

log(`Layouts done: ${layoutResults.filter(Boolean).map(r => `${r.file.split('/').pop()}=${r.changed ? 'changed' : 'skipped'}`).join(', ')}`)

// ---------------------------------------------------------------------------
// Phase 2 + 3 — site pages: apply then verify (pipeline, no barrier)
// ---------------------------------------------------------------------------
const EMBEDDED_SITES = [
  'resources/views/livewire/sites/backends.blade.php',
  'resources/views/livewire/sites/caching.blade.php',
  'resources/views/livewire/sites/cdn.blade.php',
  'resources/views/livewire/sites/cli-console.blade.php',
  'resources/views/livewire/sites/commits.blade.php',
  'resources/views/livewire/sites/database.blade.php',
  'resources/views/livewire/sites/deploy-control.blade.php',
  'resources/views/livewire/sites/deploy-hooks.blade.php',
  'resources/views/livewire/sites/deploy-script.blade.php',
  'resources/views/livewire/sites/deploy-sync-groups.blade.php',
  'resources/views/livewire/sites/deployment-detail.blade.php',
  'resources/views/livewire/sites/deployments-list.blade.php',
  'resources/views/livewire/sites/edge-deployment-detail.blade.php',
  'resources/views/livewire/sites/edge-preview-comments.blade.php',
  'resources/views/livewire/sites/edge-settings.blade.php',
  'resources/views/livewire/sites/env-diff.blade.php',
  'resources/views/livewire/sites/errors.blade.php',
  'resources/views/livewire/sites/files.blade.php',
  'resources/views/livewire/sites/index.blade.php',
  'resources/views/livewire/sites/logs.blade.php',
  'resources/views/livewire/sites/monitor.blade.php',
  'resources/views/livewire/sites/repository.blade.php',
  'resources/views/livewire/sites/resources.blade.php',
  'resources/views/livewire/sites/schedule.blade.php',
  'resources/views/livewire/sites/serverless-routing.blade.php',
  'resources/views/livewire/sites/settings.blade.php',
  'resources/views/livewire/sites/show.blade.php',
  'resources/views/livewire/sites/site-app-logs.blade.php',
  'resources/views/livewire/sites/site-environment.blade.php',
  'resources/views/livewire/sites/site-log-viewer.blade.php',
  'resources/views/livewire/sites/webserver-config.blade.php',
  'resources/views/livewire/sites/wordpress/wordpress-section.blade.php',
  'resources/views/livewire/sites/worker-env-comparison.blade.php',
  'resources/views/livewire/sites/workers.blade.php',
  'resources/views/livewire/sites/workspace-insights.blade.php',
  'resources/views/livewire/sites/workspace-pipeline.blade.php',
  'resources/views/livewire/sites/workspace-systemd.blade.php',
]
const SITES = (args && Array.isArray(args.sites) && args.sites.length) ? args.sites : EMBEDDED_SITES

const APPLY_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['file', 'action', 'title', 'summary'],
  properties: {
    file: { type: 'string' },
    action: { type: 'string', enum: ['added', 'skipped'], description: 'added = hero-card inserted; skipped = wizard/redirect/not-applicable' },
    title: { type: 'string', description: 'The hero title used (or empty if skipped).' },
    summary: { type: 'string' },
  },
}

const VERIFY_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['file', 'ok', 'issues'],
  properties: {
    file: { type: 'string' },
    ok: { type: 'boolean', description: 'true if single-root preserved, hero-card well-formed (when added), and no broken Blade' },
    issues: { type: 'string', description: 'Empty string if ok; otherwise the concrete problem.' },
  },
}

phase('Sites')

const siteResults = await pipeline(
  SITES,
  // Stage 1 — apply
  (file) => agent(`Add the shared <x-hero-card> header to the site page Blade file: ${file}

STEP 1 — Read ${file} and (if helpful) its backing Livewire class under app/Livewire/Sites/ to
understand the page's purpose.

STEP 2 — Decide:
- If this page is a real, content-bearing site page (a tab/section like Backends, Caching, CDN,
  Database, Deployments, Logs, Monitor, Schedule, Settings, Workers, etc.), ADD a hero-card.
- If it is a pure creation WIZARD, redirect shim, multi-step flow, or a page that is essentially a
  single full-bleed wizard with its own bespoke chrome (e.g. create, create-custom, choose-app,
  clone, promote, scaffold-journey, site-setup, edge-provisioning), then SKIP it — set action:"skipped"
  and make NO edit. When unsure, prefer SKIP and explain why.

STEP 3 (if adding) — Author the hero-card:
- eyebrow: :eyebrow="__('Site')" (or a more specific group like 'Deployments' if the page clearly
  belongs to one).
- title: a concise __('…') title for the page (e.g. 'Backends', 'Caching', 'Database').
- description: one short __('…') sentence describing what the page does. Keep it factual; do not invent features.
- icon: a fitting Heroicon slug (no prefix), e.g. database->'circle-stack', logs->'document-text',
  monitor->'chart-bar', schedule->'clock', workers->'cpu-chip', caching->'bolt', cdn->'globe-alt',
  files->'folder', settings->'cog-6-tooth', deployments->'rocket-launch'. Pick the closest sensible one.
- Do NOT add a stats slot unless the page ALREADY computes obvious counts you can safely reuse; an
  intro-only hero is the default and is fine.
- If the page already shows a redundant plain title/description block (e.g. an <x-page-header> or a
  hand-written <h1>/<h2> header at the top), REMOVE that old header so there is exactly one header.
  If removing is risky/unclear, leave it and note it in the summary.

${SINGLE_ROOT_RULE}

Make the edit with the Edit tool. Then re-read the top of the file to confirm balanced Blade and a
single root. Return the structured result.

${COMPONENT_DOC}`, { label: `site:${file.split('/').pop().replace('.blade.php', '')}`, phase: 'Sites', schema: APPLY_SCHEMA }),

  // Stage 2 — verify
  (applied, file) => {
    if (!applied || applied.action !== 'added') {
      return { file, ok: true, issues: applied ? `skipped: ${applied.summary}` : 'apply stage returned null' }
    }
    return agent(`Verify the hero-card edit to the site page: ${file}

Re-read ${file} fresh and check:
1. SINGLE ROOT: the view has exactly one top-level root element (the <x-hero-card> must be a child
   INSIDE the root, not a second sibling). ${SINGLE_ROOT_RULE}
2. The <x-hero-card ...> opening tag and any <x-slot:*> tags are well-formed and properly closed.
3. There is exactly ONE page header (no leftover <x-page-header>/<h1>/<h2> title block duplicating
   the hero).
4. Blade directives are balanced (@if/@endif, @foreach/@endforeach, @php/@endphp, etc.) — the edit
   did not truncate or break the rest of the page.

If you find a concrete problem, FIX it with the Edit tool (e.g. move the hero inside the root, close
a tag, remove a duplicate header), then set ok:true with issues describing what you fixed. Only set
ok:false if you cannot fix it. Return the structured result.

${COMPONENT_DOC}`, { label: `verify:${file.split('/').pop().replace('.blade.php', '')}`, phase: 'Verify', schema: VERIFY_SCHEMA })
  },
)

const clean = siteResults.filter(Boolean)
const added = clean.filter(r => r.ok && r.issues !== undefined)
const problems = clean.filter(r => r.ok === false)

return {
  layouts: layoutResults.filter(Boolean),
  sitesProcessed: SITES.length,
  siteResults: clean,
  problems,
}
