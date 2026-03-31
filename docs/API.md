# dply API (v1)

Use the API from CI/CD (e.g. GitHub Actions, GitLab CI) to list servers and trigger deploys without storing SSH keys in CI. Authenticate with an API token tied to an organization.

## Base URL

```
https://your-app.com/api/v1
```

Use your actual app URL (e.g. `https://dply.example.com`).

## Authentication

Send your API token on every request using either header:

- **Bearer:** `Authorization: Bearer <your-token>`
- **X-API-Key:** `X-API-Key: <your-token>`

Create and revoke tokens in **Organization settings → API tokens** (org admins only). The full token is shown only once when you create it.

**Roles and plan limits** (who can create servers/sites, deployer restrictions, Free tier caps) are documented in **[ORG_ROLES_AND_LIMITS.md](./ORG_ROLES_AND_LIMITS.md)**.

## Rate limit

API routes are limited to **60 requests per minute per token**. Exceeding the limit returns `429 Too Many Requests`.

## Endpoints

### List servers

Returns servers for the token’s organization.

```http
GET /api/v1/servers
Authorization: Bearer <token>
```

**Example (curl):**

```bash
curl -s -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-app.com/api/v1/servers"
```

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Production",
      "status": "ready",
      "deploy_command": "cd /var/www && git pull && npm run deploy",
      "ip_address": "203.0.113.10",
      "provider": "digitalocean",
      "created_at": "2026-03-17T12:00:00.000000Z"
    }
  ]
}
```

### Trigger deploy

Runs the server’s deploy command (same as “Deploy” in the UI). The token’s organization must own the server; otherwise you get `403 Forbidden`.

```http
POST /api/v1/servers/{server_id}/deploy
Authorization: Bearer <token>
```

**Example (curl):**

```bash
curl -s -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-app.com/api/v1/servers/1/deploy"
```

**Response (200):**

```json
{
  "message": "Deploy started.",
  "output": "... command output ..."
}
```

**Errors:**

- `403` – Server belongs to another organization.
- `422` – No deploy command set on the server.
- `500` – Deploy failed (e.g. SSH error); see `error` in the body.

### Run command (optional)

Runs an arbitrary command on the server. The token’s organization must own the server.

```http
POST /api/v1/servers/{server_id}/run-command
Authorization: Bearer <token>
Content-Type: application/json

{"command": "your shell command"}
```

**Example (curl):**

```bash
curl -s -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"command": "uptime"}' \
  "https://your-app.com/api/v1/servers/1/run-command"
```

**Response (200):**

```json
{
  "message": "Command completed.",
  "output": "..."
}
```

**Errors:**

- `403` – Server belongs to another organization.
- `422` – Missing or invalid `command` (max 1000 chars).
- `500` – Command failed; see `error` in the body.

## Errors

- **401 Unauthorized** – Missing or invalid token.
- **403 Forbidden** – Token valid but not allowed for this resource (e.g. server in another org).
- **429 Too Many Requests** – Rate limit exceeded (60/min per token).

## Sites

### List sites

```http
GET /api/v1/sites
Authorization: Bearer <token>
```

Requires `sites.read`. Returns sites for servers in the token’s organization.

### Trigger site deploy (Git)

```http
POST /api/v1/sites/{site_id}/deploy
Authorization: Bearer <token>
Content-Type: application/json
```

Optional JSON body:

- `sync` (boolean) – run deploy synchronously in the HTTP request (long timeout).
- Header **`Idempotency-Key`** (optional) – same key within 24 hours returns the cached JSON result (`200`) after the first run completes; concurrent duplicate keys get `409` while a deploy is in flight.

**Deployer role:** organization members with the **deployer** role may use deploy/read abilities only; they cannot use `commands.run` even if the token lists `*`.

### List recent deployments

```http
GET /api/v1/sites/{site_id}/deployments?limit=20
Authorization: Bearer <token>
```

Requires `sites.read`.

### Get one deployment

```http
GET /api/v1/sites/{site_id}/deployments/{deployment_id}
Authorization: Bearer <token>
```

Requires `sites.read`. Includes `log_output` (may be redacted for obvious secret patterns).

## Deploy webhook (HTTP, not under `/api/v1`)

`POST /hooks/sites/{site_id}/deploy` — no API token; authenticate with HMAC (see [DEPLOYMENT_FLOW.md](./DEPLOYMENT_FLOW.md)). Per-site rate limit: `DPLY_WEBHOOK_MAX_ATTEMPTS_PER_MINUTE` (default 30/min).
