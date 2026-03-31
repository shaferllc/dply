# Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the main `/dashboard` page into a richer, premium control center while preserving truthful data and existing empty-state flows.

**Architecture:** Keep the dashboard as a single Livewire page, expand the data passed from `App\Livewire\Dashboard`, and replace the Blade layout with a premium hero, actionable overview cards, and stronger empty-state treatment. Use existing route names and existing organization/server data only.

**Tech Stack:** Laravel, Livewire v3, Blade, Tailwind CSS v4, PHPUnit feature tests

---

### Task 1: Lock the new dashboard contract with tests

**Files:**
- Modify: `tests/Feature/DashboardTest.php`
- Test: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write a failing test**
- [ ] **Step 2: Run the dashboard test and verify the new assertions fail**
- [ ] **Step 3: Keep guest redirect coverage intact**

### Task 2: Expand dashboard data for the redesigned page

**Files:**
- Modify: `app/Livewire/Dashboard.php`

- [ ] **Step 1: Add only the minimal new view data needed**
- [ ] **Step 2: Keep data truthful and derived from real routes or existing models**
- [ ] **Step 3: Avoid pushing presentation-heavy logic into the component**

### Task 3: Redesign the dashboard view

**Files:**
- Modify: `resources/views/livewire/dashboard.blade.php`

- [ ] **Step 1: Replace the plain header with a premium hero**
- [ ] **Step 2: Add overview, insights, quick actions, and polished server empty/list states**
- [ ] **Step 3: Use existing route names only and keep unavailable states honest**

### Task 4: Verify and clean up

**Files:**
- Modify: `tests/Feature/DashboardTest.php`
- Modify: `app/Livewire/Dashboard.php`
- Modify: `resources/views/livewire/dashboard.blade.php`

- [ ] **Step 1: Run the focused dashboard test suite**
- [ ] **Step 2: Read lints for edited files and fix straightforward issues**
- [ ] **Step 3: Summarize what changed and any residual gaps**
