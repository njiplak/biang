# SaaS Starter — TL;DR

A multi-tenant B2B SaaS starter that ships with the boring parts already done:
accounts, workspaces and teams, subscription billing, limits, and an admin
console for the people who run it. You build your product in place of the
example **Projects** feature.

**Stack:** Laravel 12 · Inertia 2 + React 19 + TypeScript · Tailwind 4 ·
Pest (~1,160 tests) · Dodo Payments (merchant of record, so it owns tax and
invoices) · spatie permission / data / query-builder / medialibrary.

---

## Customers

**Accounts**
- Sign up. A plan picked on the pricing page carries through signup into
  checkout.
- Email verification, password reset, and confirm-password for sensitive
  actions.
- Two-factor auth with recovery codes.
- Manage active browser sessions.
- Change email (the change has to be confirmed).
- Delete your account.

**Workspaces (the tenant and the thing being billed)**
- A workspace is created during onboarding. You can belong to several and
  switch between them.
- Roles: owner, admin, billing manager, member, viewer.
- Invite people by email (resend or revoke), change roles, remove members,
  transfer ownership, and export the member list to Excel.
- Closing a workspace makes it recoverable for 30 days. After that it is
  anonymised rather than hard-deleted.

**Billing (all on one page)**
- Plans can be monthly, annual or **lifetime** (paid once).
- 14-day trial with a card taken up front. Only monthly plans have a trial,
  and each person gets one, ever.
- Change plan any time, with a preview of the prorated charge before
  confirming. Downgrades wait for the renewal date.
- Cancel at the end of the paid period, with optional feedback, and resume
  before it ends.
- Add-ons: extra quantity (e.g. seats), unlocks, and metered usage.
- Invoice history, plus a link to Dodo's portal for cards and invoices.
- Failed payments get a grace period with reminders. After that the
  workspace goes read-only: no data is lost, but nothing can be changed.
- Emails for trial ending, plan changed, payment failed, cancelled, and
  add-on changed.

**Limits**
- Each plan sets limits per feature (seats, projects, …).
- Going over a limit blocks writes and names which limit was hit.
- Hitting the seat limit while inviting offers to buy a seat in place.

## Staff (separate admin console, 2FA required)

| Area | What staff can do |
|---|---|
| Dashboard | MRR/ARR, churn, trial conversion, monthly trends, failed-payment recovery, lifetime count |
| Customers | Search and export customers. Grant or change a plan, extend a trial, override a limit, suspend, impersonate (logged) |
| Catalog | Create plans, prices and add-ons without an engineer. Prices publish to Dodo automatically |
| Billing ops | Retry failed webhooks, see who is in a failed-payment grace period, data-integrity alarms |
| Audit trail | Every staff action, filterable and exportable |
| Content | Announcement banners, plus CMS pages (terms, privacy) |
| Access | Staff accounts, roles and permissions, app settings, scheduled-job health |

## Behind the scenes

- **Dodo webhooks:** signature-checked, safe to receive twice, processed on a
  queue, and tolerant of events arriving out of order.
- **Hourly reconcile:** asks Dodo directly about every live subscription and
  fixes any drift.
- **Scheduled jobs:**

  | Job | Runs |
  |---|---|
  | Trial-ending warnings | Daily |
  | Convert ended trials | Hourly |
  | Failed-payment reminders | Hourly |
  | Reconcile subscriptions | Hourly |
  | Expire grace periods | Daily |
  | Purge closed workspaces | Daily |

- **Public pricing API:** `GET /api/pricing`, so a separate marketing site
  shows the same prices the app sells.
- **Funnel events:** signed up, email verified, workspace created, checkout
  started, trial started, subscription activated.

## Where to start building

Replace the example **Projects** feature with your product:
- `app/Http/Controllers/Project`
- `app/Service/Project`
- `resources/js/pages/project`

Add anything you want to limit by plan as a key in `App\Support\Features`.
Each plan then sets its limit from the admin catalog.
