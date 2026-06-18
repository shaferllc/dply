export const meta = {
  name: 'hero-card-refactor',
  description: 'Refactor pages that hand-roll a hero header onto the shared <x-hero-card> component',
  phases: [
    { title: 'Refactor', detail: 'Replace each hand-rolled hero header with <x-hero-card>' },
    { title: 'Verify', detail: 'Re-check each edited page for fidelity + blade validity' },
  ],
}

const CANDIDATES = [
  'resources/views/livewire/auth/device-approval.blade.php',
  'resources/views/livewire/backups/files.blade.php',
  'resources/views/livewire/billing/analytics.blade.php',
  'resources/views/livewire/billing/invoices.blade.php',
  'resources/views/livewire/billing/show.blade.php',
  'resources/views/livewire/cloud/create.blade.php',
  'resources/views/livewire/cloud/database-create.blade.php',
  'resources/views/livewire/credentials/panel.blade.php',
  'resources/views/livewire/dashboard.blade.php',
  'resources/views/livewire/fleet/blast-radius.blade.php',
  'resources/views/livewire/imports/ploi/migration-progress.blade.php',
  'resources/views/livewire/marketplace/index.blade.php',
  'resources/views/livewire/notifications/index.blade.php',
  'resources/views/livewire/org-networking.blade.php',
  'resources/views/livewire/organizations/activity.blade.php',
  'resources/views/livewire/organizations/automation.blade.php',
  'resources/views/livewire/organizations/index.blade.php',
  'resources/views/livewire/organizations/members.blade.php',
  'resources/views/livewire/organizations/realtime-app.blade.php',
  'resources/views/livewire/organizations/realtime.blade.php',
  'resources/views/livewire/organizations/secrets.blade.php',
  'resources/views/livewire/organizations/settings.blade.php',
  'resources/views/livewire/organizations/show.blade.php',
  'resources/views/livewire/organizations/teams.blade.php',
  'resources/views/livewire/profile/referrals.blade.php',
  'resources/views/livewire/projects/index.blade.php',
  'resources/views/livewire/scripts/edit.blade.php',
  'resources/views/livewire/serverless/create.blade.php',
  'resources/views/livewire/serverless/journey.blade.php',
  'resources/views/livewire/servers/create-managed.blade.php',
  'resources/views/livewire/servers/create/step-review.blade.php',
  'resources/views/livewire/servers/create/step-type.blade.php',
  'resources/views/livewire/servers/create/step-what.blade.php',
  'resources/views/livewire/servers/create/step-where.blade.php',
  'resources/views/livewire/servers/import-from-digital-ocean.blade.php',
  'resources/views/livewire/servers/index.blade.php',
  'resources/views/livewire/servers/recent-actions-log.blade.php',
  'resources/views/livewire/settings/api-keys.blade.php',
  'resources/views/livewire/settings/backup-configurations.blade.php',
  'resources/views/livewire/settings/bulk-notification-assignments.blade.php',
  'resources/views/livewire/settings/cli-authentications.blade.php',
  'resources/views/livewire/settings/hub.blade.php',
  'resources/views/livewire/settings/security.blade.php',
  'resources/views/livewire/settings/source-control.blade.php',
  'resources/views/livewire/settings/ssh-keys.blade.php',
  'resources/views/livewire/settings/webserver-templates.blade.php',
]

const COMPONENT_DOC = `
The reusable component lives at resources/views/components/hero-card.blade.php. API:

  <x-hero-card
      :eyebrow="__('Settings')"      {{-- small uppercase label; optional --}}
      :title="__('Profile')"          {{-- required --}}
      :description="__('…')"          {{-- optional supporting paragraph --}}
      icon="cog-6-tooth"              {{-- Heroicon slug WITHOUT the heroicon-o- prefix; optional --}}
      tone="auto"                     {{-- icon badge tone; default 'auto'. Pass an explicit tone if the old code used one --}}
      iconSize="md"                   {{-- 'default' | 'md' | 'lg'; default 'md' --}}
  >
      <x-slot:topAction> … </x-slot:topAction>   {{-- optional top-RIGHT action (the action that sat opposite the title) --}}

      {{-- default slot = the row of action PILLS that sat under the title --}}
      <x-outline-link href="…" wire:navigate>…</x-outline-link>

      <x-slot:stats>                  {{-- optional right-hand stat tiles: paste the EXISTING <dl>...</dl> here verbatim --}}
          <dl class="grid grid-cols-3 gap-2"> … </dl>
      </x-slot:stats>

      {{-- Use a 'leading' slot instead of the 'icon' prop ONLY when the old badge was fully custom (not a single heroicon). --}}
  </x-hero-card>

The component renders: dply-card section → padding → flex header (icon-badge + eyebrow/title/description on the
left, topAction on the right) → a 12-col grid below with action pills (col-span-7) and stats (col-span-5).
All user-facing strings stay wrapped in __('…').
`.trim()

// ---------------------------------------------------------------------------
phase('Refactor')

const APPLY_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['file', 'action', 'summary'],
  properties: {
    file: { type: 'string' },
    action: { type: 'string', enum: ['refactored', 'skipped'] },
    summary: { type: 'string', description: 'What was mapped (icon/eyebrow/title/topAction/stats), or why skipped.' },
  },
}

const VERIFY_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['file', 'ok', 'issues'],
  properties: {
    file: { type: 'string' },
    ok: { type: 'boolean' },
    issues: { type: 'string', description: 'Empty if ok; otherwise the concrete problem (and whether you fixed it).' },
  },
}

const results = await pipeline(
  CANDIDATES,
  // Stage 1 — refactor
  (file) => agent(`Refactor the hand-rolled hero header in this Blade page onto the shared <x-hero-card> component: ${file}

STEP 1 — Read ${file}. Locate the hand-rolled hero HEADER: a block (usually near the top, the page's
leading title block) of the shape:
    <section class="dply-card overflow-hidden"> … <x-icon-badge> … <p class="… uppercase …">EYEBROW</p>
    <h2 …>TITLE</h2> <p …>DESCRIPTION</p> … action pills … <dl …>stat tiles</dl> … </section>
It may also be a <div> rather than <section>, and the grid/layout details vary.

STEP 2 — Decide:
- If there IS a genuine hand-rolled hero header (the page's main title block, icon-badge + eyebrow/title),
  REFACTOR it (step 3).
- If the dply-card+icon-badge match is NOT a page hero header — e.g. it's a generic content card, an
  embedded panel's section header, a stat/summary card, or the page is a pure wizard step whose "hero"
  is really wizard chrome — then SKIP (action:"skipped", make NO edit) and explain. When unsure, SKIP.

STEP 3 — Replace ONLY that hero block with <x-hero-card>, preserving content EXACTLY (this is a
faithful refactor, NOT a redesign):
- eyebrow/title/description: copy the exact expressions/strings (keep __()/interpolation as-is).
- icon: if the old badge wrapped a single <x-heroicon-o-NAME .../>, pass icon="NAME". If the badge had an
  explicit tone (e.g. tone="emerald") or size, pass tone=/iconSize= to match. If the badge was fully
  custom (multiple elements, image, conditional icon), use a <x-slot:leading> with the original badge markup.
- topAction: if an action sat to the RIGHT of the title (opposite it, e.g. a Documentation link or an
  "Add …" button), put it in <x-slot:topAction>.
- action pills: the row of pill links/buttons under the title → the default slot.
- stats: paste the existing <dl>…</dl> (and any conditional @class on tiles) VERBATIM into <x-slot:stats>.
  Do not restyle tiles.
- Keep any @php blocks the page uses to compute tile values — they stay where they are (above the card).
- Remove ONLY the old hero wrapper markup you replaced. Leave the rest of the page (tabs, content,
  modals, breadcrumb @push) untouched. Preserve the single top-level root element.

Make the edit with Edit. Re-read the region to confirm balanced Blade. Return the structured result.

${COMPONENT_DOC}`, { label: `refactor:${file.replace('resources/views/livewire/', '').replace('.blade.php', '')}`, phase: 'Refactor', schema: APPLY_SCHEMA }),

  // Stage 2 — verify
  (applied, file) => {
    if (!applied || applied.action !== 'refactored') {
      return { file, ok: true, issues: applied ? `skipped: ${applied.summary}` : 'apply stage returned null' }
    }
    return agent(`Verify the hero-card refactor of: ${file}

Re-read ${file} fresh and check:
1. The <x-hero-card> and any <x-slot:*> tags are well-formed and closed.
2. CONTENT FIDELITY: the eyebrow, title, description, icon, action pills, top-right action, and stat
   tiles all carry over from the original hand-rolled hero — nothing dropped or visually downgraded.
3. Exactly ONE page header now (no leftover orphan <section class="dply-card …"> hero remnant or a
   stray closing </section>/</div> from the block that was replaced).
4. SINGLE ROOT: the view still has exactly one top-level root element.
5. Blade directives balanced (@if/@endif, @foreach/@endforeach, @php/@endphp, etc.); page body intact.

If you find a concrete problem, FIX it with Edit, then set ok:true describing the fix. Only set ok:false
if you cannot fix it. Return the structured result.

${COMPONENT_DOC}`, { label: `verify:${file.replace('resources/views/livewire/', '').replace('.blade.php', '')}`, phase: 'Verify', schema: VERIFY_SCHEMA })
  },
)

const clean = results.filter(Boolean)
return {
  processed: CANDIDATES.length,
  results: clean,
  problems: clean.filter(r => r.ok === false),
}
