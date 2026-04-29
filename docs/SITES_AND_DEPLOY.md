# Sites, DNS & deploy

How sites relate to servers, how domains and certificates fit together, and how deploys run—including webhooks and zero-downtime options.

## Sites and servers

A **site** belongs to a **server** in your organization. The control plane stores Git metadata, environment configuration, and deploy settings; your server runs the web stack (for example nginx) and application runtime.

## Domains and DNS

When you attach a primary domain, dply can use an organization **server provider** credential (where supported) to align DNS for previews, certificates, and automation. Pick a **DNS zone (apex)** validated for that account so challenges and records stay consistent.

Full arbitrary DNS editing is not required for the default flows—focus on the apex you control and the hostnames dply manages for you.

## HTTPS

Certificates are obtained using ACME (for example Let’s Encrypt). Depending on setup, validation may use **HTTP-01** or **DNS-01**. Site settings expose the fields you need when a DNS challenge is required.

## Deploy strategies (VM sites)

For traditional VM deployments, dply supports:

- **Atomic (zero-downtime)** — release directories with a `current` symlink (and similar layout). New code is prepared beside the live release, then traffic switches when ready.
- **Simple** — updates the live checkout in place; faster to reason about, but not zero-downtime.

You can switch strategy from **Site → Settings → Deploy** where the product exposes it.

## Webhooks

Git providers can call dply to **queue a deployment** when you push to the configured branch. The app validates the request (signature or shared secret, depending on configuration) and enqueues a deploy job.

Your install also exposes an HTTP endpoint for provider-style deploy hooks; configure it in the site’s deploy / webhook settings in the UI.

## Related

- [Source control & deploy flow](/docs/source-control)
- [Connect a cloud provider](/docs/connect-provider)
