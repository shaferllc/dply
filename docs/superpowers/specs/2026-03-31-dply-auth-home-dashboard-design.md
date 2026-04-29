# dply Auth Home Dashboard Redesign

> **Superseded 2026-04-28.** The `apps/dply-auth` Laravel app this spec targeted was deleted as part of merging all product lines into the single root app. Auth home is now provided by the root app's user dashboard. This spec is kept for historical reference only.

---

## Goal

Redesign the signed-in `apps/dply-auth/resources/views/home.blade.php` page from a plain confirmation card into a premium account home that feels like a flagship product surface for the broader dply ecosystem.

The new page should:

- feel visually polished and intentionally branded
- make the signed-in state feel valuable rather than empty
- help users understand account health and what they can do next
- connect the auth app to the rest of the dply product line

## Problem

The current screen confirms that the user is signed in, shows their name and email, and offers a logout button. While functional, it feels transitional rather than product-grade. It does not reinforce the role of `dply Auth` as a shared identity center, and it leaves too much empty space without giving the user meaningful next steps.

## Chosen Direction

Use a `modern flagship` visual direction with a `flagship command deck` structure.

This means:

- a large hero section with stronger visual hierarchy and branded atmosphere
- useful dashboard panels below the hero rather than fake analytics
- clear next-step guidance for account, security, and connected products
- a premium but high-trust tone that stays appropriate for an auth surface

## User Experience

When a signed-in user lands on the page, they should immediately understand three things:

1. they are signed in to the shared dply identity layer
2. their account is healthy and ready to use across products
3. they have clear destinations and actions available from this page

The page should feel confident and aspirational without looking like a marketing landing page or a fake data dashboard.

The page must support these baseline user states without broken layout or missing actions:

- user has a name
- user has no name and only an email address
- product destination URLs are available
- product destination URLs are not available
- account/settings routes are available
- account/settings routes are not available

In all cases, the page should remain truthful, complete, and visually polished without exposing broken links or dead-end actions.

## Layout

### 1. Hero

The top section should become a large branded hero with:

- a welcome message that uses the user's name when present, and falls back to a neutral signed-in heading when the user has no name
- supporting copy that explains this account powers access across dply products
- one or two status chips showing only verifiable signed-in or informational state
- a decorative but restrained background treatment using the existing brand palette

The hero is responsible for the emotional impact of the page and should set the flagship tone.

For the no-name fallback, avoid awkward greetings. Use a heading pattern such as `Your dply account is ready` or equivalent neutral copy, then show the email address in supporting content.

Hero status chips must stay truthful. Acceptable chip content includes things like `Signed in`, `Shared account`, or other generic informational labels. Do not imply verified readiness, protection level, or product access unless that state is actually available.

### 2. Dashboard Grid

Below the hero, present a grid of focused cards with clear purposes:

All cards should be explicitly classified as one of:

- navigational: links to a real route or product URL
- informational: static content with no click action

Do not use ambiguous placeholder links. If a destination is not available, render the card as informational with copy such as `Coming soon` or `Available soon across the dply platform`, and do not style it like an active primary call to action.

#### Account Overview

Shows:

- signed-in identity
- primary email address
- concise account status copy

Purpose:

- reinforce trust
- make the page feel like a real account center

#### Security Posture

Shows:

- security framing such as account protection or review reminders
- a short checklist of high-value next steps

Purpose:

- make the auth page feel useful
- encourage stronger account hygiene

This should avoid inventing backend-driven state that does not exist. If real two-factor state is not yet wired into the view, the content should remain truthful and generic.

If real account-security state is available, the card may reflect it. Otherwise, it should show generic best-practice guidance only.
Do not imply that protections are enabled, healthy, or verified unless that state is actually available in the app.

#### Product Destinations

Shows cards for:

- BYO
- Cloud
- Edge
- Serverless
- WordPress

Each card should have:

- a concise label
- a short description
- a visual treatment that makes the ecosystem feel coherent

Purpose:

- position `dply Auth` as the identity hub for multiple products
- make the page feel connected to a bigger platform

If destination URLs are not yet available, render those cards as non-interactive informational cards with explicit unavailable-state copy. Do not use `#`, dead links, or buttons that imply immediate access.

#### Quick Actions

Shows:

- profile or account management entry point if available
- security/settings-oriented actions if available
- logout action

Purpose:

- surface clear paths without cluttering the hero
- make logout visible but secondary

Required behavior:

- logout must always be present
- primary account-management action should only render if a real route exists
- if no account-management or security routes exist yet, show a concise informational message instead of empty space

## Visual Design

The redesign should follow these principles:

- keep the current brand colors and premium earth-tone palette
- add more depth through layering, borders, subtle glow, and soft shadows
- preserve readability and a high-trust feel
- use a denser, more intentional composition than the current single-card layout

The page should feel more like a polished product home than a placeholder auth checkpoint.

## Content Strategy

Copy should be:

- concise
- confident
- ecosystem-aware
- product-forward rather than promotional

Avoid:

- fake charts or fabricated metrics
- vague marketing filler
- overexplaining authentication mechanics

## Technical Approach

The work should be implemented primarily in `apps/dply-auth/resources/views/home.blade.php`.

Use existing Tailwind v4 classes and theme tokens from `apps/dply-auth/resources/css/app.css`. Prefer composing the redesign directly in Blade without introducing unnecessary new components unless reuse becomes obvious during implementation.

Keep logic minimal in the view. If any new data is needed beyond the authenticated user, prefer simple computed values that can be derived safely in Blade or passed in from the controller only if necessary.

The Blade view may use simple presentation conditionals, but route existence checks and non-trivial state shaping should be prepared before rendering when possible. Avoid embedding substantial decision logic directly in the template.

## Accessibility

The page should:

- preserve strong text contrast
- maintain clear heading hierarchy
- keep interactive elements obvious and keyboard friendly
- avoid decorative effects that reduce legibility

## Testing And Verification

Verification should focus on:

- visual review of the signed-in auth home
- confirming the page renders for authenticated users
- checking that existing logout behavior still works
- lint review for the edited Blade or related files if needed

Automated test changes are optional unless the implementation introduces new behavior that creates meaningful regression risk.

## Out Of Scope

The redesign should not:

- introduce fake analytics or recent activity
- require new backend infrastructure for dashboard data
- reshape the larger auth flow
- add speculative product routing that is not ready

## Success Criteria

The redesign is successful if:

- the page includes a hero, account overview, security posture, product destinations, and quick actions sections
- logout remains functional and is styled as a lower-emphasis action than the primary hero or navigation actions
- no section depends on fabricated data
- unavailable destinations or actions render as clearly unavailable, not broken
- the page works for authenticated users who have either a full name or only an email address
- the hero uses a neutral fallback heading when the authenticated user has no name, with the email shown elsewhere in supporting content
- hero chips display only truthful, verifiable, or explicitly generic informational labels
- the page visibly frames `dply Auth` as the shared identity center through ecosystem-oriented copy and a product destinations section that names the connected dply products
