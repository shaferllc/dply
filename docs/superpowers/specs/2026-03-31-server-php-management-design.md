# Server PHP Management Design

## Goal

Add a PHP management experience for BYO servers that clearly separates server-level PHP package management from site-level PHP runtime configuration.

The new experience should:

- let operators manage installed PHP versions at the server level
- expose the default CLI PHP version and the default PHP version for new sites
- allow operators to edit version-specific server PHP configuration such as FPM ini, CLI ini, and pool config
- keep per-site PHP version selection and PHP runtime settings on each site
- feel consistent with the existing server workspace rather than introducing a detached admin screen

## Problem

The current server workspace already stores and displays PHP version data in a few places, but PHP management is fragmented:

- server provisioning captures a default PHP version
- sites already store `php_version`
- site pages expose some PHP-related fields
- operational pages such as services and logs already reason about PHP-FPM versions

What is missing is a clear operator-facing control surface:

- there is no dedicated place to see which PHP versions are installed on a server
- there is no guarded workflow for installing, uninstalling, or changing the default server PHP version
- there is no obvious boundary between server-owned PHP concerns and site-owned PHP concerns

## Chosen Direction

Use a `split ownership` model:

- the server workspace gets a dedicated `PHP` page for server-level PHP management
- each site keeps its own PHP version and runtime settings on the site page

This keeps infrastructure concerns close to the server while keeping application runtime choices close to the site that uses them.

## User Experience

Operators should be able to answer these questions quickly:

1. which PHP versions are installed on this server
2. which version is the CLI default
3. which version is the default for newly created PHP sites
4. which sites currently use each installed version
5. what PHP runtime settings apply to a specific site

The server-level page should feel operational and safety-oriented. The site-level controls should feel application-specific and local to that site.

## Layout

### 1. Server Workspace `PHP` Page

Add a new top-level server workspace page, parallel to `Services`, `Sites`, `Logs`, and `Settings`.

This page owns server-level PHP responsibilities only:

- installed PHP versions
- default CLI PHP version
- default PHP version for new sites
- version-specific PHP configuration files and templates that belong to the server runtime
- version usage summary across current sites
- guarded install, patch, and uninstall actions
- server-level PHP inventory refresh state

This page should not become the editing surface for per-site runtime values such as memory limit or upload size.

### 2. Server PHP Page Structure

The page should be organized into focused cards:

#### Summary Card

Shows:

- current CLI/default version
- current default version for new PHP sites
- count of installed versions
- total PHP sites on the server

This card is for quick orientation, not detailed editing.

#### Installed Versions Card

Shows each available or installed PHP version in a list similar in spirit to Forge-style management, but adapted to the existing dply workspace language.

Each row should make these states easy to scan:

- version label such as `PHP 8.3`
- whether the version is installed
- whether it is the CLI default
- whether it is the default for new sites
- how many sites currently use it
- whether uninstall is blocked because the version is still in use

Actions may include:

- install
- patch or update packages for that version
- set as CLI default
- set as default for new sites
- edit FPM ini for that version
- edit CLI ini for that version
- edit FPM pool template or pool config for that version
- uninstall when safe

Dangerous actions must be visibly guarded and should explain why an action is blocked when the version is still in use.

Version-specific edit actions should be grouped under a contextual `Edit` menu or similar secondary-action affordance so the main row keeps install/default actions easy to scan.

#### Site Usage Card

Shows PHP sites on the server with:

- site name or primary domain
- current site PHP version
- quick navigation to the site page

This card is informational and navigational. It should not duplicate the full per-site editing workflow inline.

#### Status Or Guidance Card

Shows any refresh errors, package action status, or explanatory guidance such as:

- changes affect the server package inventory
- per-site runtime settings are managed on the site page
- uninstall is blocked while sites still depend on a version

#### Version Configuration Editing Surface

Each installed version should expose a server-owned editing flow for configuration that belongs to the PHP runtime on that server rather than to a specific site.

This editing surface should cover, at minimum:

- FPM ini content for that version
- CLI ini content for that version
- pool configuration or pool template content for that version

The first version does not need a full IDE-like editor, but it should provide a deliberate editing workflow with:

- a clear indication of which file or template is being edited
- raw content editing or structured text editing
- server-side verification before save is accepted
- save feedback
- failure feedback
- a note when a restart or reload is required for changes to take effect

These edits belong on the server PHP page because they affect the server runtime for that PHP version broadly, not one specific site.

## Site-Level PHP Configuration

Per-site PHP configuration remains on the site page because the site already owns `php_version` and related runtime behavior.

Each PHP site should gain a focused PHP section or card that includes:

- selected PHP version, constrained to versions installed on the server
- max upload filesize
- memory limit
- max execution time
- OPcache state or toggle
- Composer authentication entry point
- extension management entry point or summary

The exact UI can use progressive disclosure, but the ownership boundary should stay clear:

- server page manages server package availability and defaults
- site page manages which version and runtime settings a given site uses

## Data Model And State

The design should build on existing data before introducing new tables.

### Existing Data To Reuse

- `Site::php_version` for the selected site runtime version
- server metadata for persisted server-level PHP defaults and inventory snapshots
- existing site and server relationships for usage summaries
- existing service and log helpers that already infer PHP-FPM versions

### New Persisted Server Metadata

Store server-level PHP preferences in server metadata for the first pass, for example:

- installed version inventory snapshot
- default CLI PHP version
- default PHP version for new sites
- last inventory refresh timestamp
- last package action status or failure metadata if useful

This keeps the first implementation lightweight while preserving room for a more explicit PHP package model later if the surface grows substantially.

### Source Of Truth And Reconciliation

Remote SSH inventory is authoritative for real server state:

- actual installed PHP versions
- detected CLI default version
- whether expected binaries or services are present

Persisted server metadata is authoritative for cached summaries and user-selected preferences such as:

- default PHP version for newly created sites
- last known inventory snapshot
- last successful refresh timestamp
- last action outcome metadata

If remote inventory and persisted metadata disagree, the UI should treat the remote result as authoritative for current operational state and should surface the mismatch as stale or refreshed state rather than guessing.

A successful inventory refresh should overwrite the cached installed-version snapshot and detected CLI default in persisted metadata immediately. User preference data that cannot be inferred from remote state, such as the default PHP version for new sites, should be preserved unless it is now invalid, in which case it should be flagged and require user correction.

Any destructive or state-changing server action must revalidate against fresh server-side state immediately before execution.

## Behavior Rules

### Inventory

The server PHP page should be backed by SSH-driven inventory and persisted summary state.

Inventory should determine:

- which PHP versions are actually installed
- whether expected binaries or services are available
- which version is currently configured as the CLI default when detectable

The UI should make it obvious when data is stale and when a refresh is in progress or has failed.

### Available Versions Definition

The installable version list should be limited to PHP versions that dply explicitly supports for the server's detected OS and package-repository strategy.

This means:

- supported versions may be shown as installable
- unsupported versions should either be hidden or shown disabled with explanation
- if inventory detects an unknown installed version outside the supported catalog, the UI should show it as detected state but not necessarily as a manageable install target

### Page Entry States

The server PHP page must define clear behavior for these states:

- provisioning not complete
- SSH unavailable
- inventory has never run
- last inventory refresh failed
- server environment is unsupported for managed PHP package actions

In these states, the page should still explain the current situation clearly and disable or withhold package actions that cannot run safely.

### Defaults

Two defaults should be modeled distinctly:

- CLI default version for server shell usage
- default PHP version for newly created PHP sites

These may often be the same, but the design should not force them to be the same concept in the UI or state model.

### New Site Flow

The server-level default for new PHP sites should act as the preselected value in PHP site creation flows, not as an unchangeable forced value.

This means:

- new PHP site forms should prefill the server default when one is available
- operators may override that preselected value during site creation
- non-PHP site types should ignore this default
- if the saved default later becomes unavailable, creation should require explicit user selection from currently installed versions rather than silently choosing a replacement

Changing the server default should affect future site creation flows, not retroactively rewrite existing sites.

### Action Ownership And Side Effects

Ownership must remain explicit:

- the server PHP page owns install, patch, uninstall, inventory refresh, and server default changes
- the site page owns per-site PHP version selection and site-local runtime settings

Site pages may only choose among versions that are already installed and supported on the server.

Site-level edits must not auto-install missing PHP packages in the first version. If a required version is unavailable, the site page should block that choice and direct the operator back to the server PHP page.

Server-level version configuration editing also belongs exclusively to the server PHP page. Site pages must not edit shared version files such as version-wide CLI ini, FPM ini, or pool templates.

### Uninstall Safety

A PHP version cannot be uninstalled when:

- one or more sites still use that version
- it is still selected as the default for new sites
- it is still the CLI default, unless the user first switches the default elsewhere

The UI should explain which condition is blocking the action.

Uninstall should use a preflight plus execute flow:

- the visible button state may use the latest known inventory snapshot
- the action itself must revalidate against fresh state on the server immediately before execution
- if state changed since render, the uninstall should fail safely with a clear reason
- blocked confirmations should list the sites or defaults that still depend on the version

### Package Action Lifecycle

Server package actions such as install, patch, set default, and uninstall should follow one consistent lifecycle.

The first version should assume these actions are remote and potentially slow, so the UX should include:

- a queued or in-progress state
- clear loading or lockout behavior that prevents double-submit
- success and failure feedback
- automatic inventory refresh after a successful action, or a clear stale-state warning after failure

Actions should be treated as idempotent where practical. For example, installing an already-installed version or setting a default to its current value should fail gracefully or no-op without corrupting state.

Only one server-level PHP package action should run per server at a time. Concurrent actions from separate sessions should be rejected or serialized by the action layer so the UI never assumes two package mutations can proceed safely in parallel.

Version configuration edit actions should use the same server-level serialization guard when they touch shared files, so the UI does not allow overlapping writes to the same PHP version config surface.

### Patch Action Semantics

The `Patch` action should be defined narrowly as updating the installed packages for that PHP version within the server's package manager flow.

The UI should not imply a zero-risk action. It should warn that patching may restart related PHP-FPM services or otherwise affect running workloads, depending on the package manager and OS behavior.

### Version Configuration Editing

Server-level PHP config editing should treat each editable target as a shared runtime artifact.

The first version should support:

- reading the current content for the selected version and target
- verifying proposed content before save is accepted
- editing and saving the content back to the server
- surfacing whether a reload or restart is recommended or required afterward

If the expected config file does not exist or cannot be detected for the server OS, the UI should explain that clearly instead of presenting a broken editor.

The design should prefer explicit target labels such as `FPM ini`, `CLI ini`, and `Pool config` over generic wording.

Validation must happen on the server before edited content replaces the live file. The UI should not rely on client-side checks alone.

The verification flow should:

- validate the proposed content against the selected target before the live file is replaced
- use the appropriate PHP or service-level validation command for that target when available
- block save when validation fails
- return validation error output clearly enough for the operator to correct the file

When validation succeeds, the system may proceed with saving the file and should then report whether a reload or restart is still required.

### Site Editing Safety

Per-site PHP version choices should only allow installed server versions.

If a site currently references a version that is no longer installed, the site page should show that mismatch clearly and guide the operator toward a valid installed version.

### Mismatch Recovery

When a site references a PHP version that is not currently installed:

- the site remains viewable and editable
- the PHP section should visibly flag the mismatch
- the site should offer a direct path back to the server PHP page to install or restore a valid version
- the server PHP page should surface those mismatched sites in its usage or warning area

The first version does not need bulk remediation, but it should make one-by-one remediation clear.

### Authorization

The spec should assume distinct permissions for:

- viewing the server PHP page
- refreshing inventory
- running package actions and changing server defaults
- editing per-site PHP version and runtime settings

Implementation should follow existing server and site authorization patterns so lower-permission users can be prevented from mutating package state even if they can view parts of the workspace.

## Technical Approach

The implementation should likely include:

- a new `app/Livewire/Servers/WorkspacePhp.php` Livewire component
- a new `resources/views/livewire/servers/workspace-php.blade.php` view
- a new workspace nav item in the server workspace config
- a small PHP management service layer for inventory, defaults, and package actions
- server-side read and write helpers for PHP version config files
- updates to site editing UI to expose the per-site PHP section

The service layer should keep SSH/package logic out of the Livewire component as much as practical.

The implementation should also define one narrow server-side action contract so package operations always:

- read fresh state
- perform the remote action
- persist the updated cached summary
- report success or failure back to the UI

For version configuration editing, the contract should similarly:

- resolve the target file or template path for the selected version
- read current content safely
- validate that the requested target is editable for the current environment
- verify the proposed content before replacing the live file
- write updated content
- return any follow-up guidance such as reload or restart requirements

If the remote action succeeds but local persistence fails, the UI should treat the server as changed but local state as stale and prompt a refresh instead of pretending the action fully completed.

## Integration With Existing Patterns

The design should follow existing server workspace conventions:

- top-level operational areas get dedicated workspace pages
- informational summaries and actions live in branded cards
- destructive or stateful actions are explicitly guarded
- site-level settings stay with the site when they materially affect only that site

This should feel more like `Services` plus `Sites` working together than like a hidden `Settings` subsection.

## Accessibility

The new UI should:

- keep action labels explicit rather than icon-only
- not rely on color alone for installed, active, or blocked states
- keep disabled or blocked actions understandable
- preserve keyboard-accessible navigation to site pages and actions

## Testing And Verification

Verification should cover:

- rendering the new server workspace PHP page
- correct visibility of installed, default, and in-use states
- uninstall guards when a version is referenced by sites or defaults
- site PHP settings only offering installed versions
- clear mismatch handling when a site references a non-installed version
- persistence of server-level default settings
- reconciliation between stale metadata and fresh remote inventory
- action-time revalidation when state changes after render
- failed remote package actions and stale-state messaging
- in-progress action states and double-submit prevention
- new site flows applying the server default as a preselected value
- override behavior during site creation
- unsupported or unknown version rendering
- permission enforcement for view versus mutate paths
- direct remediation links between the site page and server PHP page
- rendering of version-level edit actions for installed versions
- reading and saving FPM ini, CLI ini, and pool config targets
- clear handling when an expected config target is unavailable on the server
- serialization or lock behavior for concurrent shared config edits

Automated tests should focus on guards, rendering contracts, and state persistence where they materially reduce regression risk.

## Out Of Scope

The first version should not:

- introduce a full standalone PHP package database model
- move all site settings onto the server PHP page
- manage non-PHP runtimes through the same interface
- add speculative metrics or fake status data

It may edit shared PHP runtime files, but it should not attempt to build a full general-purpose remote file manager.

## Success Criteria

The work is successful if:

- the server workspace has a dedicated `PHP` page for installed versions and defaults
- operators can see which sites use each PHP version
- operators can open and save version-level server PHP config targets such as `FPM ini`, `CLI ini`, and `Pool config`
- uninstall actions are blocked when they would break site or default state
- site pages own site PHP version selection and runtime settings
- the new UI matches existing server workspace patterns and terminology
