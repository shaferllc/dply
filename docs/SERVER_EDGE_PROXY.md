# Edge proxy add-on

The **Edge proxy** section manages an optional L7 reverse proxy (Traefik, HAProxy, Envoy, OpenResty) in front of the primary **Webserver**.

## Traffic path

Typical layout:

```
Client → Edge proxy :80 → Caddy/Nginx on high ports → app
```

The primary webserver preference stays in **`meta.webserver`** for restore when you remove the proxy.

## Engine tabs

Pick an engine on **Overview**, then use per-engine tabs for routes, dashboards, and upgrades. **Coming soon** engines show preview panels until enabled.

## Traefik dashboard

When Traefik is active, open the in-app **Dashboard** proxy (auth-gated) to view routers and services without exposing the port publicly.

## Not a webserver switch

Edge proxy is an **add-on**, not a replacement target in **Switch webserver**. Install and remove via **Edge proxy** workflows only.

## Related sections

- **Webserver** — origin webserver behind the proxy
- **Site → Routing** — hostnames the proxy forwards
- **Configuration** — proxy config files when allowlisted
