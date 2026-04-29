# HTTP API

Use organization-scoped **API tokens** from **Profile → API keys** to call the dply HTTP API from CI/CD, scripts, or integrations. Tokens are sent as **Bearer** credentials.

## Base URL

All versioned routes are under:

```text
{APP_URL}/api/v1
```

Replace `{APP_URL}` with your application origin (for example `https://app.example.com`).

## Authentication

Send the header:

```http
Authorization: Bearer YOUR_TOKEN_HERE
```

Tokens belong to a **user** and **organization**. Each token lists **abilities** (permissions). The **deployer** organization role can only use abilities allowed by organization policy (typically read + deploy).

Creating new tokens may require a **Pro** subscription when your instance enables `DPLY_API_TOKENS_REQUIRE_PAID_PLAN`.

## Common endpoints

| Method | Path | Typical ability |
| --- | --- | --- |
| `GET` | `/api/v1/servers` | `servers.read` |
| `POST` | `/api/v1/servers/{server}/deploy` | `servers.deploy` |
| `POST` | `/api/v1/servers/{server}/run-command` | `commands.run` |
| `GET` | `/api/v1/sites` | `sites.read` |
| `POST` | `/api/v1/sites/{site}/deploy` | `sites.deploy` |
| `GET` | `/api/v1/sites/{site}/deployments` | `sites.read` |
| `GET` | `/api/v1/sites/{site}/deployments/{deployment}` | `sites.read` |
| `GET` | `/api/v1/servers/{server}/firewall` | `network.read` |
| `POST` | `/api/v1/servers/{server}/firewall/apply` | `network.write` |
| `GET` | `/api/v1/insights/summary` | `insights.read` |
| `GET` | `/api/v1/servers/{server}/insights` | `insights.read` |

Operator-only routes under `/api/v1/operator/*` use separate middleware and are not for general API tokens.

## Metrics

`POST /api/metrics` is used for server metrics ingestion and related callbacks; it is **not** part of the bearer-token API surface described above.

## Related

- [Organization roles & plan limits](/docs/org-roles-and-limits)
- [Source control & deploy flow](/docs/source-control)
