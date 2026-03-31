# Servers Index Redesign

## Goal

Redesign `resources/views/livewire/servers/index.blade.php` into a premium, operator-focused page that preserves the current filtering, grouping, and management flows while substantially improving hierarchy, scanability, and product polish.

## Problem

The current `/servers` page is functionally solid, but it still reads as a conventional resource index. The controls, page heading, and server collection all work, yet the page does not fully communicate that this is a high-trust operational surface. The top of the page lacks a strong summary layer, and the collection area could better prioritize the information an operator needs to scan quickly.

## Chosen Direction

Use a `premium operations rail` layout with an `operator focus` tone.

This means:

- a stronger summary section at the top of the page
- a polished, integrated control bar for search, filters, and view mode
- a server collection that improves hierarchy without removing existing capabilities
- an overall design that feels premium but remains decisively practical

## User Experience

When an operator lands on `/servers`, the page should answer these questions quickly:

1. how healthy is my fleet right now
2. what server or issue most likely needs attention next, based on existing visible status, health, scheduled removal, and insight signals
3. where can I scan, filter, and manage servers efficiently

The page should feel faster and more intentional, not more decorative.

## Layout

### 1. Premium Operations Hero

The page should open with a more substantial top section containing:

- the `Servers` heading and operator-oriented supporting copy
- real summary metrics derived from current page data
- the primary create-server action
- secondary actions only when they already exist as real destinations or current page affordances; do not introduce speculative provider workflows

The top section should feel premium and high-trust while staying truthful to the underlying data.

### 2. Control Rail

The existing controls should remain, but be redesigned into a cleaner command bar:

- search
- status filter
- sort selection
- reset filters action
- list and grid view toggle

The control rail should read as one cohesive surface rather than several unrelated utility controls.

### 3. Server Collection

Grouped server rendering should remain in place.

Each server row or grid card should make these items easy to scan:

- server name
- IP address or provisioning state
- provider
- status
- health state when relevant
- site count
- insight badge when available
- scheduled removal state when present
- manage and remove actions where available

The redesign should improve information hierarchy, spacing, and visual grouping, but must not remove current management affordances.

### 4. Fallback States

The page must support:

- no servers in scope
- no servers matching current filters

Both fallback states should feel designed and intentional. They should provide clear next steps without using broken actions or vague placeholder links.

When filters produce zero results, the summary area should either reflect the filtered zero-result state or clearly label itself as based on the current visible set.

## Truthfulness And Data Rules

The redesign must use only real data already available on the page or straightforward derivations of it.

Acceptable summary data includes:

- total visible servers
- grouped server counts
- count of ready or non-ready servers
- open insight totals that already exist in the current dataset
- health-state counts or ratios only if they can be derived directly from loaded server health fields without introducing new scoring logic

All summary metrics should reflect the full filtered dataset returned by the current query after team scope, search, status filter, and sort inputs are applied. They should not depend on whether the page is currently in list or grid mode.

Do not add:

- fake performance charts
- fabricated uptime percentages
- deployment activity that is not actually loaded
- placeholder metrics that appear live but are not real

## Interaction Rules

The redesign must preserve existing interactions:

- search updates the list
- filters and sort continue to work
- list and grid view continue to work
- manage links keep their current destinations
- remove actions keep their current behavior
- scheduled removal messaging and cancel flow remain available

No new top-level hero actions should be introduced beyond existing page-level actions or destinations already available from the current servers page flow.

## Visual Direction

The page should feel:

- premium
- operational
- high-trust
- quick to scan

Use the existing brand palette and app styling conventions. Add stronger hierarchy, richer spacing, and more intentional framing, but avoid clutter that competes with server data.

## Technical Approach

The implementation should primarily modify:

- `app/Livewire/Servers/Index.php`
- `resources/views/livewire/servers/index.blade.php`

If additional derived summary values are needed, they may be prepared in the Livewire component. Keep the component focused on truthful data preparation and leave presentational styling in Blade.

## Accessibility

The redesign should:

- preserve strong contrast for text and status indicators
- keep controls obvious and keyboard accessible
- avoid hiding key server state behind color alone
- maintain readable density in both list and grid views

## Testing And Verification

Verification should cover:

- authenticated access to `/servers`
- presence of the redesigned top-level content
- retention of empty-state guidance
- continued rendering of grouped server content
- no broken links or removed management actions in the rendered view
- summary metrics updating truthfully when search or status filters change
- consistent rendering and truthful summaries in both list and grid modes

Automated tests should be updated when they provide meaningful coverage of the new page contract.

## Out Of Scope

The redesign should not:

- change server filtering behavior
- redesign downstream server workspace pages
- add new backend metrics infrastructure
- introduce speculative operational data

## Success Criteria

The redesign is successful if:

- the page includes a premium summary section, a cohesive control rail, and the grouped server collection
- existing list and grid modes still render server management affordances
- no summary metric is fabricated
- the page remains useful for both empty and populated organizations
- operators can quickly identify status, health, provider, and next actions from the collection view
