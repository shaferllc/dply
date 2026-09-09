# ADR: standalone managed WordPress surface — scope before build

Status: proposed (2026-09-04)

## Context

README's product table lists WordPress as **"Planned — Managed WordPress on
dply-controlled infra. Not yet implemented."** That line is wrong in a way that
matters for planning: a substantial WordPress capability already ships on the
BYO surface, and an inventory finds it is not a stub.

What exists today, all on a customer's own VM:

| Capability | Where |
|---|---|
| WP-CLI over the RemoteCli engine, with risk classification and an audit trail | `app/Modules/RemoteCli/Services/WpCli.php` |
| Workspace UI — plugins, themes, users, cron, core version, db size | `app/Livewire/Sites/WordPress/WordPressSection.php` (770 lines) |
| Operator CLI: `dply:wp`, hardening apply, cron switch, plugin update-all, search-replace | `app/Console/Commands/Wp*.php` (5 commands) |
| Vulnerability advisories from Wordfence Intelligence | `app/Services/WordPress/Advisories/` |
| Scaffolding a new WordPress site | `app/Modules/Scaffold/…/ScaffoldWordPressPipeline.php` |
| Runtime detection, contextual docs, cron presets, console actions | `config/product/*` |

So the honest statement is not "WordPress is unimplemented". It is: **dply
manages WordPress well on infrastructure the customer owns, and has no product
where dply owns the infrastructure.** Those are different products with
different economics, and conflating them is what makes the roadmap item look
larger or smaller than it is depending on who reads it.

## Decision

Do not open a build epic yet. The decision this ADR asks for is a **boundary**,
because every question below changes the shape of the work, and answering none
of them is how a "managed WordPress" epic becomes six months of drift.

### 1. Tenancy — the question that decides everything else

- **Isolated VM per site.** Reuses the entire existing stack: provisioning,
  the RemoteCli engine, snapshots, backups, the workspace UI. Margin is bounded
  by one VM per customer site, which is poor at the low end of the market where
  managed WordPress actually sells.
- **Shared host, many sites.** The margin story, and a different product:
  per-site PHP-FPM pools, filesystem isolation, a noisy-neighbour policy, and
  per-site resource accounting. `config/servers/shared_host.php` and the
  fairness advisor already exist, which is a real head start — and a real
  constraint, since that code was written for BYO hosts.
- **Containers.** Neither reuse nor the shared-host margin; a third runtime to
  operate.

Nothing else can be specified until this is settled.

### 2. Infrastructure ownership and billing

dply-owned infra means dply carries the compute cost, so pricing stops being
"seat plus your own cloud bill" and becomes a margin per site. The Billing
module's usage calculators assume the customer's own resources; a dply-owned
surface needs its own cost model, as Edge and Realtime each did.

### 3. Migration in

Nobody starts a managed WordPress host empty. An import path — from a host, a
backup, or a duplicator plugin — is table stakes, and `app/Modules/Imports`
covers servers and sites, not WordPress installs.

### 4. What is reused rather than rebuilt

The WP-CLI engine, advisories, hardening and the workspace UI should carry over
unchanged: they operate over a shell on a box, and a dply-owned box is still a
box. Provisioning, routing, TLS and backups are where a managed surface diverges,
because dply owns the hostnames and the recovery guarantees.

## Consequences

- The README line is wrong today in both directions — it understates the BYO
  capability and overstates how close a managed surface is. Corrected separately
  (ISS-005).
- No implementation epics until tenancy (1) is decided. Filing them first would
  produce tickets whose scope inverts depending on that answer.
- If the answer is isolated-VM-per-site, this is mostly packaging and pricing on
  top of what ships today, and is small. If it is shared-host multi-tenancy, it
  is a new runtime with its own isolation and accounting story, and is large.
  The gap between those two readings is exactly why this is an ADR and not a
  backlog item.
