# Server PHP Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated server-level PHP management page plus site-level PHP runtime controls, including guarded version actions and verified config editing.

**Architecture:** Add a new `Servers\WorkspacePhp` Livewire page for server-owned PHP inventory, defaults, package actions, and shared version config editing. Keep site-owned PHP version selection and runtime settings on `Sites\Show`, backed by a focused PHP management service layer that performs fresh-state checks, remote actions, and pre-save config verification before touching live files.

**Tech Stack:** Laravel, Livewire v3, Blade, Tailwind CSS v4, PHPUnit feature tests

---

## File Structure

**Create:**

- `app/Livewire/Servers/WorkspacePhp.php` - Livewire page for server-level PHP management.
- `resources/views/livewire/servers/workspace-php.blade.php` - Blade view for the server PHP workspace.
- `app/Services/Servers/ServerPhpManager.php` - inventory, refresh, defaults, persisted metadata, and guarded package action orchestration.
- `app/Services/Servers/ServerPhpConfigEditor.php` - version-specific config read, verify, and write flows for CLI ini, FPM ini, and pool config targets.
- `tests/Feature/WorkspacePhpTest.php` - page rendering, auth, refresh states, action guards, and UI contract coverage.
- `tests/Unit/Services/ServerPhpManagerTest.php` - inventory reconciliation, refresh persistence, uninstall guards, default logic, and stale-state behavior.
- `tests/Unit/Services/ServerPhpConfigEditorTest.php` - config target resolution, validation failures, concurrency, and write contract behavior.

**Modify:**

- `routes/web.php` - add the new `servers.php` Livewire route.
- `config/server_workspace.php` - add the `PHP` workspace nav item.
- `app/Livewire/Sites/Show.php` - add site-level PHP form state, validation, mismatch messaging, and save actions.
- `resources/views/livewire/sites/show.blade.php` - add the site-level PHP section/card.
- `app/Livewire/Sites/Create.php` and `resources/views/livewire/sites/create.blade.php` if site creation owns PHP defaulting.
- `tests/Feature/ServerTest.php` - update any server workspace route/nav expectations if they assume the old nav shape.
- `tests/Feature/SiteTest.php` or the existing site feature coverage file if present - add site-level PHP interaction and site-create coverage if that file already owns those flows.

**Check before editing:**

- `app/Livewire/Servers/WorkspaceServices.php` and `resources/views/livewire/servers/workspace-services.blade.php` for workspace action patterns.
- `app/Livewire/Servers/Concerns/InteractsWithServerWorkspace.php` for shared server workspace behavior.
- `app/Services/Servers/ServerSystemdServicesCatalog.php`, `app/Services/Servers/ServerSystemLogReader.php`, and `config/server_manage.php` for existing PHP-version inference patterns.
- `resources/views/livewire/servers/workspace-sites.blade.php` and `resources/views/livewire/sites/show.blade.php` for current site PHP display and editing conventions.

### Task 1: Lock the new server PHP workspace contract with tests

**Files:**

- Create: `tests/Feature/WorkspacePhpTest.php`
- Modify: `routes/web.php`
- Modify: `config/server_workspace.php`

- [ ] **Step 1: Write a failing feature test for authenticated access to the new `servers.php` workspace route**
- [ ] **Step 2: Add assertions for the `PHP` workspace nav item and the new page heading**
- [ ] **Step 3: Add assertions for entry-state messaging such as server not ready, no inventory yet, or SSH unavailable**
- [ ] **Step 4: Run the focused feature test and verify it fails because the route/page do not exist yet**

### Task 2: Add the server PHP workspace route and shell

**Files:**

- Create: `app/Livewire/Servers/WorkspacePhp.php`
- Create: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `routes/web.php`
- Modify: `config/server_workspace.php`

- [ ] **Step 1: Add the `servers/{server}/php` Livewire route with a `servers.php` route name**
- [ ] **Step 2: Add the `PHP` item to the server workspace navigation in the intended position near `Services` and `Sites`**
- [ ] **Step 3: Create the minimal `WorkspacePhp` Livewire page using the existing server workspace boot and authorization pattern**
- [ ] **Step 4: Create the minimal Blade view using `x-server-workspace-layout` and a placeholder server PHP summary shell**
- [ ] **Step 5: Re-run the new workspace feature test and verify the page now renders**

### Task 3: Build server PHP inventory, defaults, and reconciliation logic

**Files:**

- Create: `app/Services/Servers/ServerPhpManager.php`
- Create: `tests/Unit/Services/ServerPhpManagerTest.php`
- Modify: `app/Livewire/Servers/WorkspacePhp.php`

- [ ] **Step 1: Write unit tests for supported-version catalog selection, installed-version normalization, and source-of-truth reconciliation**
- [ ] **Step 2: Add tests for preserved user defaults versus remote authoritative state when inventory disagrees with cached metadata**
- [ ] **Step 3: Implement the minimal `ServerPhpManager` read API for supported versions, cached inventory, and current defaults**
- [ ] **Step 4: Add a fresh-inventory reconciliation method that overwrites detected installed/default state but preserves valid user preferences**
- [ ] **Step 5: Wire `WorkspacePhp` to read its summary and version-row data from `ServerPhpManager`**
- [ ] **Step 6: Run the focused unit tests and make sure they pass before moving on**

### Task 4: Add inventory refresh, persisted status, and stale-state handling

**Files:**

- Modify: `app/Services/Servers/ServerPhpManager.php`
- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `tests/Unit/Services/ServerPhpManagerTest.php`
- Modify: `tests/Feature/WorkspacePhpTest.php`

- [ ] **Step 1: Write unit tests for inventory refresh persisting the installed-version snapshot, detected CLI default, refresh timestamp, and failure metadata**
- [ ] **Step 2: Add feature coverage for `never refreshed`, `refresh running`, `refresh failed`, and `stale after failed action` page states**
- [ ] **Step 3: Implement the refresh action in `ServerPhpManager` and persist the refreshed snapshot and status metadata on the server**
- [ ] **Step 4: Handle the error path where the remote refresh or remote mutation succeeds but local metadata persistence fails by surfacing a stale-state warning**
- [ ] **Step 5: Expose a refresh trigger on `WorkspacePhp` with loading, authorization, and post-refresh UI feedback**
- [ ] **Step 6: Run the focused manager and workspace tests and make sure the refresh contract passes**

### Task 5: Render the real server PHP page states

**Files:**

- Modify: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `tests/Feature/WorkspacePhpTest.php`

- [ ] **Step 1: Expand the feature test to assert summary card content, installed-version rows, and site-usage visibility**
- [ ] **Step 2: Add page-state coverage for unsupported environment, stale inventory, and refresh failure messaging**
- [ ] **Step 3: Render the summary card, installed versions card, site usage card, and guidance card in the Blade view**
- [ ] **Step 4: Keep blocked or unavailable actions visible but clearly disabled with explanatory copy**
- [ ] **Step 5: Run the feature test again and verify the rendered page contract passes**

### Task 6: Add authorization coverage for view, refresh, mutate, and site-edit actions

**Files:**

- Modify: `tests/Feature/WorkspacePhpTest.php`
- Modify: `tests/Feature/SiteTest.php` or the existing site feature coverage file
- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `app/Livewire/Sites/Show.php`

- [ ] **Step 1: Write a failing feature test for a user who can view the server but cannot mutate package state**
- [ ] **Step 2: Add a failing feature test for blocked refresh, package-action, and config-edit mutations**
- [ ] **Step 3: Add a failing feature test for site PHP edits being blocked when the user lacks site update permission**
- [ ] **Step 4: Apply existing authorization patterns to `WorkspacePhp` action methods and site PHP save methods**
- [ ] **Step 5: Re-run the focused permission tests and verify view versus mutate behavior is explicit**

### Task 7: Add guarded server package actions

**Files:**

- Modify: `app/Services/Servers/ServerPhpManager.php`
- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `tests/Unit/Services/ServerPhpManagerTest.php`
- Modify: `tests/Feature/WorkspacePhpTest.php`

- [ ] **Step 1: Write unit tests for install, set CLI default, set new-site default, patch, and uninstall guard behavior**
- [ ] **Step 2: Add coverage for uninstall blocking when a version is still used by sites or selected as either default**
- [ ] **Step 3: Implement package-action preflight that revalidates fresh state immediately before execution**
- [ ] **Step 4: Add server-level action serialization so only one package mutation can run per server at a time**
- [ ] **Step 5: Add explicit tests for double-submit prevention and concurrent action rejection or serialization**
- [ ] **Step 6: Expose the package actions through `WorkspacePhp` with loading, success, failure, and post-action refresh behavior**
- [ ] **Step 7: Render primary row actions plus a secondary `Edit`/`More` affordance without overcrowding the table**
- [ ] **Step 8: Run the focused unit and feature tests and verify both guard logic and UI states pass**

### Task 8: Add verified version-config editing for CLI ini, FPM ini, and pool config

**Files:**

- Create: `app/Services/Servers/ServerPhpConfigEditor.php`
- Create: `tests/Unit/Services/ServerPhpConfigEditorTest.php`
- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `tests/Feature/WorkspacePhpTest.php`

- [ ] **Step 1: Write unit tests for resolving the editable target paths per PHP version and target type**
- [ ] **Step 2: Add unit tests for validation failure so invalid config is rejected before the live file is replaced**
- [ ] **Step 3: Add unit tests for unsupported or missing targets so the editor fails clearly rather than silently**
- [ ] **Step 4: Implement `ServerPhpConfigEditor` read flow for `cli_ini`, `fpm_ini`, and `pool_config` targets**
- [ ] **Step 5: Implement verify-before-write behavior using the target-appropriate server-side validation command when available**
- [ ] **Step 6: Implement serialized write behavior plus reload/restart guidance on success**
- [ ] **Step 7: Add explicit tests for concurrent shared-config edit rejection or serialization**
- [ ] **Step 8: Add the edit-target launcher on `WorkspacePhp` so a version row can open `CLI ini`, `FPM ini`, or `Pool config` editing**
- [ ] **Step 9: Add the config editor UI state that loads current content and labels the selected target clearly**
- [ ] **Step 10: Add save behavior that shows validation errors without replacing the live file and success guidance when verification passes**
- [ ] **Step 11: Run the config editor unit tests and the workspace feature test to verify the editing flow**

### Task 9: Add site-level PHP version and runtime settings

**Files:**

- Modify: `app/Livewire/Sites/Show.php`
- Modify: `resources/views/livewire/sites/show.blade.php`
- Modify: `app/Services/Servers/ServerPhpManager.php`
- Modify: `tests/Feature/SiteTest.php` or the existing site feature test file

- [ ] **Step 1: Add a failing site-page feature test for a PHP section that shows current site PHP version and available installed versions**
- [ ] **Step 2: Add test coverage for mismatch state, direct remediation links back to the server PHP page, and non-installed-version handling**
- [ ] **Step 3: Add Livewire state on `Sites\Show` for site PHP version and runtime fields such as upload size, memory limit, and max execution time**
- [ ] **Step 4: Add the site PHP card summary for OPcache state, Composer auth entry point, and extension management entry point or summary**
- [ ] **Step 5: Load installed server versions into the site page and constrain the selector to supported installed versions only**
- [ ] **Step 6: Implement the site PHP version selector save path**
- [ ] **Step 7: Implement the site-owned runtime field save path for memory, upload, and execution settings**
- [ ] **Step 8: Render mismatch state clearly when the site references a non-installed version and link back to the server PHP page**
- [ ] **Step 9: Re-run the focused site feature test and confirm the new PHP card behavior passes**

### Task 10: Connect the new-site default to site creation flows

**Files:**

- Modify: `app/Livewire/Sites/Create.php`
- Modify: `resources/views/livewire/sites/create.blade.php`
- Modify: `tests/Feature/SiteTest.php` or the existing site-create feature test file

- [ ] **Step 1: Write a failing test for PHP site creation prefilling the server default version when it is valid**
- [ ] **Step 2: Add a failing test for requiring explicit user selection when the saved default is no longer installed**
- [ ] **Step 3: Update the site creation flow to preselect the server default for PHP sites only**
- [ ] **Step 4: Preserve user override behavior during creation**
- [ ] **Step 5: Re-run the focused creation tests and verify both the happy path and invalid-default path pass**

### Task 11: Verify, lint, and tighten the UX

**Files:**

- Modify: `app/Livewire/Servers/WorkspacePhp.php`
- Modify: `resources/views/livewire/servers/workspace-php.blade.php`
- Modify: `app/Services/Servers/ServerPhpManager.php`
- Modify: `app/Services/Servers/ServerPhpConfigEditor.php`
- Modify: `app/Livewire/Sites/Show.php`
- Modify: `resources/views/livewire/sites/show.blade.php`
- Modify: related test files from earlier tasks

- [ ] **Step 1: Run the focused PHP workspace, PHP manager, config editor, and site feature tests**
- [ ] **Step 2: Run any adjacent server/site feature tests that might regress because of new nav or form behavior**
- [ ] **Step 3: Read lints for all edited files and fix straightforward diagnostics**
- [ ] **Step 4: Manually review the server PHP page and site PHP section for overcrowding, blocked-state clarity, and route correctness**
- [ ] **Step 5: Summarize any residual gaps, especially around OS-specific config-target detection or remote validation commands**
