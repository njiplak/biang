# Data retention: what we keep, about whom, for how long

**Status:** factual brief for a legal decision — not a decision itself
**Prepared:** 2026-09-08
**Audience:** whoever owns legal, plus engineering

Spec §13 lists one genuinely open question: *"Confirm that anonymising, rather
than hard-deleting, is acceptable to us legally."* That question has been open
since the spec was written, while the behaviour it describes has shipped and
runs nightly.

This document does not answer it. It sets out exactly what the system does
today so the question can be answered with facts rather than assumptions, and
it argues that §13 asks a narrower question than the one that matters.

> **Verify before relying on this.** Everything below was read out of the
> schema and the code on the date above. Code moves; this file does not. Check
> it against `PurgeClosedWorkspaces`, `AccountService::deleteAccount` and the
> migrations before making a decision on it.

---

## 1. When a customer closes a workspace

Closing is not deleting. Spec §6: *"Deleted workspaces are recoverable for 30
days (configurable), then anonymised rather than hard-deleted, so revenue
history survives."*

**Immediately:** the workspace is soft-deleted and stamped with `purge_after`
= now + `WORKSPACE_RETENTION_DAYS` (default **30**). It is recoverable for that
window.

**After the window**, `workspaces:purge` runs daily at 03:00 and:

| Action | Fields |
|---|---|
| Overwritten | `name` → "Closed workspace", `slug` → `closed-<ulid>`, `settings` → null, `suspension_reason` → null |
| Deleted outright | every `workspace_members` row, every `workspace_invitations` row |
| Stamped | `anonymized_at` |

**Deliberately retained**, because revenue history is the point: `invoice_summaries`,
the subscription and subscription-item history, usage records, entitlements,
`billing_status`, `access_status`, and **`dodo_customer_id`**.

The workspace row survives. It no longer names anyone.

---

## 2. When a person deletes their account

`AccountService::deleteAccount()` **hard-deletes** the `users` row. There is no
soft delete and no `deleted_at` column — the record is gone, not hidden.

It is refused in one case: a person who is the sole owner of any workspace must
transfer ownership first, or the workspace would be left with nobody who can
administer it.

**Removed with them, automatically:**

- `workspace_members` (cascade)
- `announcement_dismissals` (cascade)
- `notification_logs` (cascade)

**Kept, with the pointer to them nulled:**

- `workspace_invitations` — who invited, who accepted
- `workspace_members.invited_by_user_id`
- `impersonation_sessions.user_id`

That last one was, until 2026-09-08, `restrictOnDelete`, which meant **any
customer who had ever been impersonated by support could not delete their
account at all** — the request failed with a foreign key violation. It is now
nullable: the record that an impersonation happened survives with its reason,
timestamps and staff member, and only the link to the person is cut. Nobody can
erase evidence of their own impersonation by closing their account.

---

## 3. What survives a person's erasure

This is the part §13 does not ask about, and it is where the real exposure sits.

| Table | Retained about the erased person | Cleared? |
|---|---|---|
| `audit_logs` | `ip_address`, `user_agent`, the action taken, timestamps, and a now-dangling `actor_id` | **No.** `actor` is a `nullableMorphs` — no foreign key, so nothing cascades. Rows are kept indefinitely |
| `sessions` | `ip_address`, `user_agent`, a dangling `user_id` | **No FK.** Rows persist until session garbage collection |
| `password_reset_tokens` | the email address, keyed on it | **No.** Rows persist until the token expires |
| `impersonation_sessions` | `ip_address`, `user_agent` — **of the staff member**, not the customer | N/A — not the customer's data |

IP addresses are personal data under GDPR. We currently keep them, tied to a
recorded action, after the person has asked to be erased.

---

## 4. The questions for legal

1. **§13 as written:** is anonymising a workspace, rather than hard-deleting
   it, acceptable — given we do it specifically to keep revenue history?

2. **The sharper question:** after a person exercises deletion, we retain their
   IP address and user agent in `audit_logs` indefinitely, attached to a record
   of what they did. Is that defensible as a legitimate interest, or do those
   fields need scrubbing on deletion? If they need scrubbing, that is a small
   change — but it trades away part of the audit trail §10 asks for, so it is
   a decision, not a cleanup.

3. **Is 30 days right?** It is configurable per environment
   (`WORKSPACE_RETENTION_DAYS`), not per customer, and nothing depends on the
   number.

4. **`dodo_customer_id` survives anonymisation** and points at a record held by
   Dodo Payments, who are merchant of record and therefore the data controller
   for the payment record. Who is responsible for erasure on their side, and
   does our retaining the pointer matter?

5. **Residue we could clear but currently do not:** `sessions` and
   `password_reset_tokens` rows outlive the account. Both expire on their own.
   Should deletion clear them explicitly rather than waiting?

---

## 5. What engineering would do with each answer

- **Q2 says scrub** → null `ip_address` / `user_agent` on `audit_logs` rows
  whose actor was just deleted. Small, and it narrows the audit trail.
- **Q3 changes the number** → one environment variable, no deploy.
- **Q5 says clear** → delete the person's `sessions` and
  `password_reset_tokens` rows inside `deleteAccount()`. Small.
- **Q1 or Q4 says the current approach is wrong** → that is a larger
  conversation, because §6's "cancelling keeps the data indefinitely" promise
  and §4's read-only floor are both built on it.
