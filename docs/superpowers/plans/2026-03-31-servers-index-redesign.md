# Servers Index Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `/servers` into a premium, operator-focused index page while preserving existing filtering, grouping, and management behavior.

**Architecture:** Keep the current Livewire `Servers\Index` page and existing collection/query behavior, add only minimal derived summary data in the component, and replace the Blade presentation with a richer hero, cohesive control rail, and upgraded list/grid hierarchy. Summary metrics must reflect the full filtered dataset and stay truthful to currently loaded data.

**Tech Stack:** Laravel, Livewire v3, Blade, Tailwind CSS v4, PHPUnit feature tests

---

### Task 1: Lock the redesigned `/servers` page contract with tests

**Files:**
- Modify: `tests/Feature/ServerTest.php`
- Test: `tests/Feature/ServerTest.php`

- [ ] **Step 1: Write the failing test**
- [ ] **Step 2: Run the focused servers test and verify the new assertions fail**
- [ ] **Step 3: Keep guest redirect and existing list behavior coverage intact**

### Task 2: Prepare truthful summary data in the Livewire component

**Files:**
- Modify: `app/Livewire/Servers/Index.php`

- [ ] **Step 1: Add only minimal derived summary values from the filtered dataset**
- [ ] **Step 2: Keep list/grid behavior unchanged**
- [ ] **Step 3: Avoid moving presentation logic into the component**

### Task 3: Redesign the servers index Blade view

**Files:**
- Modify: `resources/views/livewire/servers/index.blade.php`

- [ ] **Step 1: Replace the plain page intro with a premium operations hero**
- [ ] **Step 2: Redesign the filter strip into a cohesive command rail**
- [ ] **Step 3: Upgrade empty state, no-results state, and list/grid hierarchy without removing actions**

### Task 4: Verify and clean up

**Files:**
- Modify: `tests/Feature/ServerTest.php`
- Modify: `app/Livewire/Servers/Index.php`
- Modify: `resources/views/livewire/servers/index.blade.php`

- [ ] **Step 1: Run the focused `/servers` feature tests**
- [ ] **Step 2: Read lints for edited files and fix straightforward issues**
- [ ] **Step 3: Summarize the redesign and remaining gaps**
