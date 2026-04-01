## Summary

The ready-state site workspace currently renders the navigation card and the
content stack as if they belong to one full-width flow. The navigation card
appears above the content instead of reading as a separate sidebar, which
makes the page feel visually broken on desktop.

This change will turn the ready-state layout into a true two-column workspace:
a sticky left sidebar for site identity and section navigation, and a distinct
main content column for the page sections.

## Goals

- Make the ready-state site page read as a sidebar plus main content layout.
- Keep the current section structure and anchors intact.
- Preserve a simple stacked layout on smaller screens.
- Avoid changing behavior or information architecture beyond layout.
- Keep page-level notices full width above the ready-state workspace grid.

## Non-goals

- No redesign of the provisioning-state layout.
- No new navigation behavior such as active-section tracking.
- No information architecture changes to section order or content.

## Architecture

The change is isolated to `resources/views/livewire/sites/show.blade.php`.

The ready-state branch will keep the existing `grid` shell, but it will be
tightened so the sidebar occupies a fixed-width desktop column and the content
occupies a separate main column. The sidebar card will remain wrapped in an
`aside` with desktop-only sticky positioning. The content cards will remain in
their existing container, but that container will become the explicit main
column so the sidebar cannot span full width above it.

The two-column layout will apply at `lg` and above. The sidebar will keep the
current rail-style width target already used by the view and will use the
existing desktop sticky offset so it remains aligned with the page rhythm
instead of introducing a new scroll position.

## Components

### Sidebar

- Keep the current identity header with hostname, IP, and external-link action.
- Keep the current section links and anchor targets.
- Constrain width on desktop so it reads like a persistent navigation rail.
- Use sticky positioning only on large screens.
- Ensure existing in-page anchor links still land on the same sections.

### Main content

- Keep all existing sections in the current order.
- Ensure the section stack lives in a dedicated right-hand column.
- Preserve current card styling and spacing.

## Data flow

No data flow changes. The view will continue to render the same site, server,
and capability-derived values already provided by the Livewire component.

## Error handling

No new error states are introduced. Existing conditional sections and actions
continue to render exactly as they do today.

Flash notices, deploy-lock messaging, and other page-level alerts remain
outside the ready-state grid so they continue to span the full content width
above both columns.

## Testing

- Manually verify the ready-state page shows a left sidebar and right content
  column on desktop widths.
- Manually verify clicking sidebar anchors still lands on the expected section.
- Manually verify the sidebar stays sticky while the main column scrolls.
- Manually verify the sidebar remains above the content on smaller screens.
- Manually verify the provisioning-state layout remains unchanged.
- Run lint diagnostics for the edited Blade file after the view change.
