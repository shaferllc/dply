# Site CDN

The **CDN / Edge** section connects optional **CDN or edge delivery** in front of this VM site.

## Enable CDN

Configure provider credentials and:

- **Origin** — this site's primary hostname or origin IP
- **Cache behavior** — static asset patterns
- **SSL mode** — full vs flexible (provider-dependent)

## Not dply Edge product

This panel is for **third-party CDN** or hybrid setups. For dply-managed static/SSG hosting, create an **Edge** site instead.

## Purge

Trigger **cache purge** after deploy when the provider API is linked. Pair with **Deploy** finish hooks.

## Related sections

- **Caching** — origin cache headers
- **Routing → Domains** — CDN CNAME targets
- **Deploy** — purge on successful release
