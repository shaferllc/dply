# Site authentication (VM)

The **Authentication** section lets you lock a VM site behind one staging access method at a time.

## Methods

| Method | Visitor experience | Enforcement |
|--------|-------------------|-------------|
| **Off** | No gate | — |
| **HTTP basic auth** | Browser username/password popup | htpasswd files under `.dply/basic-auth/` + webserver basic-auth directives |
| **Password gate** | Branded login form + cookie (similar to Edge preview protection) | On-server PHP gate under `.dply/access-gate/` + `auth_request` / `forward_auth` wiring |

Only **one** method can be active per site in this release. Switching methods removes the other method on the next webserver apply.

## HTTP basic auth

Configure:

- **Username**
- **Password** — stored as a hash; rotate from the UI when needed
- **Path scope** — `/` for the whole site, or a prefix like `/wp-admin`

When enabled, browsers show the native basic-auth dialog before any app login page loads.

**Sync from server** scans the repository for stray `.htpasswd` files and imports entries so you can manage them here.

## Password gate

Configure one or more **labeled passwords** (for example a person or team name). Each label is recorded when someone logs in successfully.

- After login, a secure cookie (`__dply_vm_access`) lasts **24 hours**
- Avoids the browser basic-auth popup
- Applies **site-wide only** (`/`) in this release
- **Login log** on the server records label, time, IP, and hostname — visible in Authentication → Recent gate logins

The gate is not enforced until at least one password is saved and the webserver apply completes. If nginx shows **500** with the gate enabled but files are missing, re-run **Apply webserver config** from Authentication.

Supported webservers: **nginx**, **Caddy**, **Traefik**, and **Apache** (Apache uses a cookie-presence redirect in v1). **OpenLiteSpeed** shows a coming-soon guard in the UI until gate wiring ships.

## Scope

Both methods apply at the **webserver vhost** layer for VM sites. They do not replace app-level auth (Laravel sessions, etc.).

## Disable

Choose **Off** and confirm, or remove the password gate / last basic-auth credential. The gate is cleared on the next webserver apply.

## Related sections

- **Routing** — staging hostnames are often paired with access protection
- **Web server config** — see generated auth directives
- Edge **Preview protection** — separate product for Edge preview hostnames
