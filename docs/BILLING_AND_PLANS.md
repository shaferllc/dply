# Billing & plans

How organization **trial limits**, **Pro** subscriptions, and **invoicing** fit together in dply BYO.

## Trial vs Pro

**Trial** (non-Pro) organizations have **organization-wide** caps on **servers** and **sites**—defaults are configurable per install but commonly a small number of each until you upgrade.

**Pro** (monthly or yearly Stripe price configured for your install) typically unlocks **unlimited servers and sites** for that organization, subject to fair use and provider capacity.

Roles and member seats are described in [Organization roles & plan limits](/docs/org-roles-and-limits).

## Subscriptions and invoices

Billing is tied to the **organization**. Owners and billing contacts manage payment method and subscription in product (exact navigation follows your UI shell).

Stripe sends **invoice emails** when enabled; profile preferences may include whether you want subscription invoice emails as an individual user.

## Tax and VAT (EU)

Where EU **VAT** collection applies, your organization can maintain valid tax identifiers. Validation may use external services (for example **VIES**) when enabled—timeouts and country rules are configured on the server.

Exact invoice presentation and tax lines follow Stripe and your organization’s details at checkout.

## API tokens and Pro

Some installs **require Pro** to **create** new HTTP API tokens while still allowing **revocation**. This is controlled by instance configuration, not by the docs page.

## Related

- [Organization roles & plan limits](/docs/org-roles-and-limits)
