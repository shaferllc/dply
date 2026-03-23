# Billing/Checkout UI – Agent Task Briefs

Cashier is already wired to `Organization`. Split the remaining work across three agents as below.

---

## Agent 1: Subscription plan config

**Goal:** Central config for subscription plans (Stripe price IDs, names, intervals) so the app and Stripe stay in sync.

**Tasks:**
- Add `config/subscription.php` (or `config/plans.php`) returning an array of plans, e.g.:
  - `id` (key), `name`, `price_id` (Stripe Price ID), `interval` (month/year), optional `description` or `features`.
- Use env vars for Stripe price IDs (e.g. `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`) so production can override; fallback to empty or placeholder so tests/local don’t require Stripe.
- Register the config in the app (Laravel will load `config/subscription.php` automatically if it’s in `config/`).
- Add a short comment or docblock at the top of the config file explaining how to set `.env` (e.g. `STRIPE_KEY`, `STRIPE_SECRET`, and the price ID vars).

**Acceptance:** `config('subscription.plans')` (or equivalent) returns the plan list; price IDs come from env.

**Relevant:** `Organization` model uses `Laravel\Cashier\Billable`; no need to change the model in this task.

---

## Agent 2: Billing page (display + link)

**Goal:** A billing page for the current organization that shows subscription status and payment method, with a link from the app.

**Tasks:**
- Add a route for the billing page, e.g. `GET organizations/{organization}/billing` or `GET billing` (resolving org from session or route). Protect with auth and ensure the user can access the organization (reuse existing org membership checks or policy).
- Add a policy or authorize so only org admins/owners can view billing (recommended); alternatively allow all org members if you prefer.
- Build the billing page (Blade view or Livewire component):
  - Show current subscription status: none / trialing / active / past_due / cancelled (use Cashier’s subscription helpers on `Organization`).
  - Show plan name if subscribed (from Agent 1’s config, keyed by Stripe price ID or subscription alias).
  - Show default payment method (e.g. “•••• 4242”) if set; otherwise “No payment method”.
  - Include placeholder buttons or links: “Subscribe” and “Manage billing” (can point to `#` or routes that Agent 3 will implement).
- Add a link to this billing page from the organization show page and/or the main nav (e.g. “Billing” next to the org name or in a dropdown).

**Acceptance:** Logged-in org admin can open the billing page from the app and see current status and placeholders for Subscribe / Manage billing.

**Relevant:** `Organization` is Billable; use `$organization->subscription()`, `$organization->defaultPaymentMethod()`, etc. Agent 1’s config should be used for plan names.

---

## Agent 3: Checkout and customer portal flows

**Goal:** Wire “Subscribe” and “Manage billing” to Stripe Checkout and Stripe Customer Portal with correct return URLs.

**Tasks:**
- **Subscribe / Upgrade:** Add a route (e.g. `POST organizations/{organization}/subscription` or `POST billing/checkout`) that accepts a plan key (or price_id). Authorize (org admin/owner). Use Cashier to create a Checkout Session for a new subscription (e.g. `$organization->newSubscription('default', $priceId)->checkout(...)`). Return a redirect to Stripe Checkout. Success and cancel URLs should point back to the org billing page (from Agent 2).
- **Manage billing:** Add a route (e.g. `GET organizations/{organization}/billing/portal` or `GET billing/portal`) that authorizes, then calls Cashier’s redirect to the billing portal (e.g. `$organization->redirectToBillingPortal(returnUrl)`). Return URL should be the org billing page.
- Replace the placeholder “Subscribe” and “Manage billing” links on the billing page with these routes (or keep the billing page in Agent 2’s scope and have Agent 3 document the route names so someone can update the buttons in one place).
- Ensure webhook handling for `customer.subscription.*` and `invoice.*` is already in place (Cashier default); no new webhook code required unless you add custom logic.

**Acceptance:** From the billing page, “Subscribe” sends the user to Stripe Checkout for the chosen plan and returns to the billing page; “Manage billing” opens the Stripe portal and returns to the billing page.

**Relevant:** Cashier docs for `newSubscription()->checkout()` and `redirectToBillingPortal()`. Use Agent 1’s config for valid price IDs.

---

## Order and dependencies

- **Agent 1** can run first (no dependency).
- **Agent 2** can run in parallel with Agent 1; it only needs to know the billing route and that plan names will come from config (can stub “Current plan” if config is not merged yet).
- **Agent 3** depends on Agent 1 (plan config) and Agent 2 (billing page URL for return links); run Agent 3 after or in parallel with Agent 2, and ensure return URLs point to the same billing route Agent 2 creates.

---

## Shared conventions

- All billing routes and views assume the **current organization** is the billing entity (from route parameter `{organization}` or session `current_organization_id`).
- Use existing auth and org membership; add a `viewBilling` or reuse `update` on `Organization` for authorization if needed.
