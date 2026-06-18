# Design System

## Overview

dply uses **Tailwind CSS v4** (via `@tailwindcss/vite`) with a custom brand palette defined in `@theme` blocks inside `resources/css/dply-theme.css`. The app imports `@tailwindcss/forms` and `@tailwindcss/typography` plugins. UI is built with Blade components (`resources/views/components/`) and Livewire components (`resources/views/livewire/`), using Tailwind utility classes throughout. No CSS Modules or CSS-in-JS is used.

## Colors

### Brand Palette

| Token | Light Value | Dark Value | Role |
|-------|-------------|------------|------|
| `--color-brand-ink` | `#171a0e` | `#eef0e8` | Primary text, primary button fill |
| `--color-brand-forest` | `#32482c` | `#9cbc92` | Hover state for primary actions |
| `--color-brand-moss` | `#5d6259` | `#b9bcb4` | Secondary/muted text |
| `--color-brand-sage` | `#688479` | `#9fb5ae` | Focus rings, accent borders |
| `--color-brand-olive` | `#5d5622` | `#c9b86a` | Warm accent |
| `--color-brand-rust` | `#9a6215` | `#d4a574` | Warning/amber tone |
| `--color-brand-copper` | `#ac6e22` | `#d9b07a` | Copper accent |
| `--color-brand-gold` | `#cda942` | `#e6d18f` | Gold highlight |
| `--color-brand-sand` | `#e1d8ac` | `#2f352c` | Muted fill, badge background |
| `--color-brand-cream` | `#fdfcf9` | `#141612` | Page background |
| `--color-brand-mist` | `#a7a69a` | `#7a7d76` | Placeholders, disabled text |

### Badge / Semantic Tone Colors

| Tone | Background | Text | Border |
|------|-----------|------|--------|
| success | `bg-green-50` | `text-green-900` | `border-green-200` |
| warning | `bg-amber-50` | `text-amber-900` | `border-amber-200` |
| danger | `bg-red-50` | `text-red-900` | `border-red-200` |
| info | `bg-brand-ink` | `text-brand-cream` | `border-brand-ink/10` |
| accent | `bg-brand-sand/30` | `text-brand-ink` | `border-brand-ink/10` |
| neutral | `bg-white` | `text-brand-moss` | `border-brand-ink/10` |

## Typography

### Font Families

- Sans: `'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'`

Font is loaded via Bunny Fonts CDN in the Blade layouts.

### Font Sizes in Use

| Tailwind Class | Value | Usage |
|----------------|-------|-------|
| `text-[11px]` | 11px | Small badge labels |
| `text-xs` | 12px | Badge labels, captions |
| `text-sm` | 14px | Body text, form inputs, buttons |
| `text-[10px]` | 10px | Uppercase label fields in docs cards |

### Font Weights

| Class | Value | Usage |
|-------|-------|-------|
| `font-semibold` | 600 | Buttons, badges, headings |

### Letter Spacing

| Class | Value | Usage |
|-------|-------|-------|
| `tracking-wide` | 0.025em | Uppercase button/badge text |
| Inline `letter-spacing: 0.14em` | 0.14em | Uppercase label fields in docs |

## Layout

### Page Shell

The standard page width is `mx-auto max-w-7xl px-4 sm:px-6 lg:px-8` (class `.dply-page-shell`).

- Max width: `1280px` (`max-w-7xl`)
- Horizontal padding: `16px` (mobile) → `24px` (sm) → `32px` (lg)

## Borders and Shadows

### Border Radius

| Tailwind Class | Value | Usage |
|----------------|-------|-------|
| `rounded-lg` | 8px | Small buttons (sm variant) |
| `rounded-xl` | 12px | Default buttons, compact cards, inputs |
| `rounded-2xl` | 16px | Cards, panels, modals, dialogs |
| `rounded-full` | 9999px | Badges, pills, spinners |

### Component Surfaces

| Component Class | Border | Background | Shadow |
|-----------------|--------|------------|--------|
| `.dply-card` | `brand-ink/10` | `white` | `shadow-sm` |
| `.dply-card-compact` | `brand-ink/10` | `white` | `shadow-sm` |
| `.dply-dropdown-panel` | `brand-ink/12` | `white` | `shadow-lg shadow-brand-ink/10` |
| `.dply-dialog` | `brand-ink/12` | `white` | `shadow-xl shadow-brand-ink/12` |
| `.dply-modal-panel` | `brand-ink/12` | `white` | `shadow-2xl shadow-brand-ink/15` |
| `.dply-page-header` | `brand-ink/10` | `white` | `shadow-sm` |
| `.dply-flyout-panel` | `brand-ink/12` | `white` | `shadow-md shadow-brand-ink/10` |
| `.dply-surface-nav` | `brand-ink/10` | `white` | `shadow-sm` |

### Focus / Selection Ring

Focus inputs: `focus:ring-2 focus:ring-brand-sage/30 focus:border-brand-sage`

Drag-selected state: `box-shadow: 0 0 0 2px rgb(104 132 121 / 0.45)` (brand-sage equivalent)

## Components

### Organization

- **111 Blade components** in `resources/views/components/` — reusable UI primitives (buttons, badges, modals, inputs, cards, alerts)
- **774 Livewire component views** in `resources/views/livewire/` — full page and partial components
- Components are kebab-cased files rendered as `<x-component-name>` in Blade
- No dedicated component library package; all components are project-local

### Key Component Primitives

- `x-primary-button` — ink-filled button, sizes `default` and `sm`
- `x-secondary-button` — outlined/ghost button
- `x-danger-button` — destructive action button
- `x-badge` — pill badge with tones: success, warning, danger, info, accent, neutral
- `x-modal` — dialog overlay with `.dply-modal-panel`
- `x-alert` — status alert
- `x-text-input` — form text field
- `x-input-label`, `x-input-error` — form label and error accessory

## Conventions

### Styling Approach

- **Framework**: Tailwind CSS v4 with `@theme` CSS custom properties
- **File pattern**: Global stylesheet (`resources/css/app.css`) imports theme + Tailwind; component-scoped overrides via `@layer components`
- **Naming**: Tailwind utility classes in Blade; custom component classes prefixed `dply-` (kebab-case)
- **Source scanning**: `@source` directives include vendor pagination views, compiled framework views, all Blade and JS files

### Dark Mode

- **Approach**: Class-based toggle on `<html class="dark">` (controlled via `resources/views/partials/theme-head.blade.php` + `theme.js`)
- All brand tokens are redefined under `html.dark { }` in `dply-theme.css`
- Tailwind custom variant: `@custom-variant dark (&:where(.dark, .dark *))`
- Dark surface base: `bg-zinc-900` / `rgb(24 24 27)` replacing `bg-white`

### Accessibility

- Focus rings on interactive elements: `focus:ring-2 focus:ring-brand-sage/30`
- Disabled states: `disabled:cursor-not-allowed disabled:opacity-50`
- `.dply-btn-busy` pattern hides button content (visibility: hidden) and shows a CSS spinner during server round-trips
