# ADR-007: dply-wordpress hosted runtime and internal provisioning

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted   |
| **Date**     | 2026-03-30 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md), [ADR-004](./0004-engine-isolation-ssh-leakage.md) |

## Context

The **dply-wordpress** product is a **hosted-only** managed WordPress line: dply provisions and operates customer sites on **dply-controlled** infrastructure. Customers do **not** supply VMs or SSH access in v1.

[ADR-004](./0004-engine-isolation-ssh-leakage.md) forbids importing BYO’s SSH stack into `apps/dply-wordpress`. The deploy engine must call **internal** provisioning (HTTP/SDK/queue workers owned by this product), not `SshConnection` from the BYO app.

The **tenant fleet** (where WordPress actually runs — VMs, containers, DBs, object storage) is an **operations choice** (AWS, GCP, DO, on-prem, etc.) and is **not** fixed in application code. This ADR defines the **control-plane contract** only.

## Decision

1. **Runtime model:** `wordpress_projects.settings.runtime` is **`hosted`** for this product line (default when omitted for deploy validation: treat as hosted).

2. **Hosted metadata** on `WordpressProject.settings` (validated on API write and before deploy):
   - **`environment_id`** — optional string, internal id for the provisioned environment (max 128 chars).
   - **`primary_url`** — optional public URL for the site (https), for routing and display.
   - **`compute_ref`**, **`data_ref`** — optional opaque strings for internal resource pointers (namespaces, cluster ids, etc.).
   - **Deploy precondition:** at least one of **`environment_id`** or **`primary_url`** must be present so a deploy targets a real or reserved hosted slot.

3. **Provisioning boundary:** `WordpressDeployEngine` delegates to a **`HostedWordpressProvisioner`** implementation. The default **`LocalHostedWordpressProvisioner`** simulates a successful internal provisioner response (deterministic `revision_id`, structured JSON `provisioner_output`) until a real HTTP/SDK adapter is wired. **No BYO imports.**

4. **Observability:** Production may add a separate HTTP provisioner that calls an internal service; configuration lives under `config/wordpress.php` (`provisioner` driver).

## Consequences

- **Positive:** Clear product boundary vs BYO; ADR-004 compliance; testable deploy path without cloud credentials.
- **Negative:** Real multi-tenant fleet still requires **separate** infra runbooks and worker services; this ADR does not choose cloud provider.

## Relation to other ADRs

- **ADR-004:** WordPress stays free of BYO SSH; provisioners are internal or duplicated SSH **only** inside `apps/dply-wordpress` if ever added — not default.
- **ADR-005:** `dply_wordpress` database remains isolated from other products.
