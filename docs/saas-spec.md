# SaaS Product Spec

**Status:** decisions locked, not yet built
**Last updated:** 2026-09-07
**Audience:** product, sales, support, engineering

This describes *what the business does*, not how it is built. No schema, no code.
Engineering translates this; nothing here should need a developer to read.

---

## 1. What we are building

A multi-tenant SaaS product where customers sign up, work in a shared
workspace with their colleagues, and pay us monthly or annually. Some
customers stay free forever. Some try the paid product for two weeks and
convert. Some grow and buy more seats.

Three separate surfaces:

| Surface | Who uses it | Where it lives |
|---|---|---|
| Marketing site | Visitors who have never signed up | **Separate project**, not this one |
| The app | Customers and their teams | This project, starts at the login page |
| Admin console | Our own staff | This project, separate login |

---

## 2. The one rule everything else follows

**We sell to workspaces, not to people.**

A workspace is the customer. It has the plan, the card, the invoice, the
seat count and the usage limits. A person is just someone with access to
one or more workspaces.

One person can belong to many workspaces at once, with a different job in
each. The same person might be the owner of a paying workspace and a
read-only guest in someone else's free one. What they are allowed to do is
decided entirely by **which workspace they are currently looking at** —
never by who they are.

This is why the app always shows a workspace switcher, and why "am I
allowed to do this" is always answered per workspace.

---

## 3. Who is involved

**Inside a workspace:**

| Role | What they can do |
|---|---|
| Owner | Everything, including billing, ownership transfer and closing the workspace |
| Admin | Manage people and settings. Cannot see or touch billing |
| Billing manager | Billing only. Cannot manage people |
| Member | Uses the product |
| Viewer | Read only |

A workspace must always have at least one owner. The last owner cannot
leave or be removed — they have to hand ownership over first.

**Outside a workspace:**

- **Platform staff** — our team, in the admin console, on a completely
  separate login. A customer account can never reach admin functions.
- **Dodo Payments** — our payment provider, and legally the seller on
  every transaction (see §8).

---

## 4. What we sell

### No free tier
There is no free plan. Every plan carries a price, and every plan can be
trialled with a card taken up front. The reasoning: a perpetual free tier
carries permanent cost and support load with no forcing function toward
revenue, and it dilutes the one number that tells us whether this is a
business — trial-to-paid conversion.

What replaces it is a **floor**: a workspace with no live subscription is
**read-only**. Everything already in it stays, stays readable, and stays
exportable-by-eye indefinitely. Nothing is deleted, and there is no
countdown. Choosing a plan turns writing back on.

Mechanically the floor is one plan row, marked `is_free`, that is not
public, has no price, and is never offered. It exists because entitlement
resolution needs something to resolve to when no subscription is live. Its
limits are deliberately unlimited: a read-only workspace cannot write
anyway, and a ceiling there would report "over limit" — naming a problem
the customer cannot fix and hiding the one they can.

> **What this costs us, stated plainly:** cancellation no longer lands
> anywhere usable, so "read-only forever" has to actually be forever. The
> moment a retention countdown is added to cancelled workspaces, this
> promise breaks and a data export becomes mandatory.

### Free trial
- 14 days *(to confirm)*, full features of a chosen paid plan.
- **A card is required to start.** We collect it up front through Dodo.
  This is the only way in: there is no card-less path to the product.
- **One trial per person, ever** — not per workspace. If someone who has
  already used their trial creates a second workspace, that workspace
  starts on free or paid, never on trial.
- At the end of day 14 it **charges automatically** and becomes a normal
  paid subscription. That is the whole point of taking the card.
- If they cancel during the trial, they are not charged and the workspace
  becomes read-only.

> **This is a commitment, not a detail:** because the trial auto-charges,
> we owe the customer clear warning emails at 3 days and 1 day before.
> Skipping those turns conversions into disputes, and with our payment
> setup a dispute is a formal chargeback, not a quiet refund.

### Paid plans
Monthly or annual. Annual is cheaper per month. Customers can switch
between the two, and between plans, at any time — the price difference is
prorated.

### Add-ons
Three kinds, and they behave differently:

| Kind | Example | How it is charged |
|---|---|---|
| Quantity | Extra seats, extra storage | Per unit, prorated when the quantity changes |
| Paid unlock | A premium capability sold separately | Flat recurring fee |
| Metered | Usage past what the plan includes | Billed on actual consumption |

**Constraint:** our payment provider allows a maximum of **10 paid add-ons
per plan**. So only things people actually *pay extra for* become add-ons.
Capabilities that are simply included at a higher tier are handled inside
our own product and cost us nothing.

---

## 5. The customer journey

### Three ways in

**Path A — "Start trial"** *(the only self-serve way in)*
Marketing site, plan and billing period already chosen → sign up → verify
email → name your workspace → enter card → 14-day trial begins →
auto-charges on day 15.

Both the plan AND the billing period are carried through signup and echoed
back at every step, so the price the card form charges is the price the
pricing page quoted.

**Path B — signed up without choosing a plan**
Possible, and it lands on a read-only workspace. Nothing is lost; they pick
a plan from the billing page whenever they are ready.

Email verification stays BEFORE the card, and not for anti-abuse reasons —
a fresh verified address is free, so it protects the one-trial-per-person
rule barely at all. It is because the trial auto-charges on day 15 and we
owe warning emails at T-3 and T-1: taking a card we intend to charge
automatically, against an address never proved to receive mail, is how a
conversion becomes a chargeback.

**Path C — "You've been invited"**
Invite email → accept → you join an **existing** workspace. You do not
create one, you do not start a trial, you do not enter a card. You inherit
whatever plan that workspace is on. If you already have an account, you
just get a new workspace in your switcher.

### Then, in the product

- Owner invites colleagues by email, choosing their role up front.
- Invites can be resent or revoked, and expire on their own.
- When the workspace hits a limit, the product says so plainly and offers
  the specific upgrade or add-on that removes the limit. It never fails
  silently.
- Billing lives on one page inside the app: current plan, usage against
  every limit, and buttons to change plan, buy add-ons, or open the
  payment provider's page for cards and invoices.

---

## 6. Workspace states

Every workspace is always in exactly one of these. This table is the
contract between product, support and engineering.

| State | How it got there | Log in | Read | Write | Being billed |
|---|---|---|---|---|---|
| **Read-only** | Never bought, or the subscription ended | Yes | Yes | **No** | No |
| **Trialing** | Card given, day 1–14 | Yes | Yes | Yes, full plan | Not yet |
| **Active** | Paying | Yes | Yes | Yes, full plan | Yes |
| **Past due** | A renewal payment failed | Yes | Yes | **Yes** — with a warning banner | Retrying |
| **Suspended** | Grace period ran out, or we suspended for abuse | Yes | Yes, export only | **No** | Stopped |
| **Over limit** | Usage exceeds what the current plan allows | Yes | Yes | **No** | Unchanged |
| **Deleted** | Owner closed it, or we removed it | No | No | No | Cancelled |

**Notes that matter commercially:**

- **Past due keeps full access on purpose.** A customer whose card expired
  is not a customer who left. Locking them out on day one of a failed
  payment is how you turn a card problem into a cancellation.
- **Cancelling does not delete anything.** The workspace goes read-only
  and every bit of the data stays, indefinitely, with no countdown. Nobody
  is removed to make the workspace fit a smaller allowance, because there
  is no smaller allowance — writing is simply off.
- **Read-only must mean complete.** Every screen has to stay readable, not
  a summary view. This is what makes the absence of a data export
  defensible, and it constrains every feature built from here on.
- **Read-only beats over limit** when both apply. An expired workspace
  cannot write at all, so naming a seat limit would point the customer at a
  problem that is not theirs to fix.
- **Deleted workspaces are recoverable for 30 days** *(configurable)*, then
  anonymised rather than hard-deleted, so revenue history survives.

---

## 7. Limits, seats, and what happens when someone hits one

**Our policy is a hard block.** When a workspace is over its limit, writing
stops until the situation is resolved. We do not silently let people exceed
what they pay for, and we do not delete their data to make it fit.

In practice:

**Adding one person too many.** Workspace is on a 5-seat plan and invites a
6th. We offer a paid seat add-on, priced and prorated, right there in the
invite flow. If they take it, the invite goes out. If they decline, the
invite is blocked. *A seat limit should be a sales moment, not a wall.*

**Downgrading with too many people.** Workspace has 8 members and wants to
move to a 5-seat plan. **The downgrade is blocked** until they remove three
people. We tell them exactly how many and let them do it in one place.

**Exceeding a usage limit.** The workspace goes read-only. They can still
read and export everything. Upgrading or buying the add-on restores writing
immediately.

> **Why this forces one specific setup decision:** our payment provider has
> its own self-serve page where customers could change their own plan. If we
> left that switched on, someone could downgrade to a 5-seat plan from
> outside our product and we would find out afterwards — the block would be
> impossible to enforce. So **plan changes happen only inside our app**, and
> the provider's plan switcher is turned off. Their page keeps cards and
> invoices, which is exactly the part we do not want to build.

---

## 8. Who does what: us vs. our payment provider

We use **Dodo Payments**, and they act as *merchant of record*. In plain
terms: they are the legal seller on every transaction, not us. They handle
sales tax and VAT in every country, they issue the actual invoice, and they
own refunds and chargebacks.

This removes a large amount of work — and a large amount of liability —
but it means the split of responsibility has to be understood by everyone,
especially support.

| Thing | Us | Dodo |
|---|---|---|
| Pricing page content | ✅ | |
| Taking the card | | ✅ |
| Sales tax / VAT, worldwide | | ✅ |
| The invoice as a legal document | | ✅ |
| Refunds and chargebacks | | ✅ |
| Updating a card | | ✅ |
| Changing plan or add-ons | ✅ | |
| Cancelling | ✅ | ✅ (both work) |
| Counting seats and usage | ✅ | |
| Deciding who can do what | ✅ | |
| Trial eligibility | ✅ | ✅ (backup check) |
| Showing payment history to our staff | ✅ (summary) | ✅ (the document) |

**Two consequences to plan around:**

1. **Money reaches us on their payout schedule**, not instantly. Finance
   should know this before launch.
2. **No manual bank transfer.** A merchant of record does not support
   "customer transfers to our account, we mark it paid." If we ever need
   local Indonesian bank transfer, that is a second payment path we would
   have to build separately.

**Our own records are the source of truth for access. Theirs is the source
of truth for money.** A workspace that has never bought does not exist in
Dodo at all, and a comped account granted by our sales team has no payment
behind it — so access can never depend on asking Dodo a question.

That said, **asking is how we stay in step.** Their notifications are the
primary transport and the one that fails silently, so we also ask directly:
on the checkout return, and hourly for every live subscription. Both land in
the same reconciler as a webhook.

---

## 9. When a payment fails

| When | What the customer sees | What we do |
|---|---|---|
| Renewal fails | Email + banner in the app. Full access continues | Provider begins retrying |
| Still failing | Reminder emails escalate in tone | Retries continue |
| Grace period ends | Workspace goes read-only. Email explains exactly why | Billing stops |
| Card fixed | Access restored immediately | Subscription resumes |

The owner and any billing manager get these emails. Regular members do not
— they should not learn about their company's card problems from us.

---

## 10. What our staff need from the admin console

Framed as jobs, not screens:

**Understand the business**
MRR and ARR. Signups this week. Trials currently running. Trial-to-paid
conversion rate. Churn. Which plans are actually selling.

**Answer a support ticket in under a minute**
Find any customer by email or workspace name. See their plan, state, seat
usage and payment history without opening a second tool.

**Reproduce a complaint**
Enter a customer's workspace as them, with an obvious banner saying so and
a permanent record that we did it.

**Close a deal / rescue a customer**
Grant a plan by hand. Extend a trial. Apply a discount or a comp. Override
a limit for one specific customer. Sales cannot wait for a deploy.

**Change what we sell**
Create and edit plans, prices, limits and add-ons without an engineer.
Retire a plan without breaking the customers already on it.

**Stop abuse**
Suspend a workspace immediately.

**Talk to everyone**
Announce maintenance or a new feature to all customers.

---

## 11. The marketing site handoff

The marketing site is a separate project. The seam between them:

- **Signup lives in our app.** The marketing site only links to it.
- **Pricing is published by our app** and read by the marketing site, so a
  price change in the admin console updates both places at once. Nobody
  retypes a price.
- **One button, one destination.** There is no "Start free". Every plan
  button carries its plan AND its billing period through signup and ends at
  the card form. The pricing feed publishes a `signup_url` per price for
  exactly this, so the monthly/annual toggle cannot be lost on the way.
- **Terms and privacy live on the marketing site.** Our app links to them
  from signup and from email footers.
- The app sits on its own address (`app.` subdomain) so the two projects
  can ship independently.

---

## 12. Decisions locked

| Question | Decision |
|---|---|
| Who is the customer we bill? | The workspace |
| Payment provider | Dodo Payments, merchant of record |
| Free tier | None. Every plan is priced; every plan can be trialled |
| Card required for trial? | Yes |
| Add-on types | Quantity, paid unlock, and metered — all three |
| Can one person be in many workspaces? | Yes |
| Admin console | Separate login, fully separated from customer accounts |
| Over a limit? | Hard block on writing |
| Trial eligibility | One per person, ever |
| Payment account | One per workspace, so ownership can transfer cleanly |
| Floor plan in the payment provider? | No — it is never sold |
| End of trial | Auto-charge |
| Cancelling | Workspace goes read-only, data kept indefinitely |
| Data retention | 30 days after DELETION, then anonymised — configurable. Cancelling is not deletion and has no countdown |
| Data export | Not offered. Read-only access in the app is the whole promise |
| Provider's self-serve plan switcher | Off. Cancellation stays on |
| Invoices | We keep a summary; the document itself stays with the provider |

## 13. Still open

**Settled since this was written:**

1. **The value metric is seats.** It is the only thing this product has that
   a customer consumes, and section 2 describes a workspace shared with
   colleagues — which is what seat pricing is for. Plans now carry a seat
   limit and nothing else.

   The consequence is worth stating plainly, because it is a rule we now
   hold ourselves to: **a plan only advertises a limit that something
   actually counts.** `projects` and `api_calls` still exist as features and
   cost nothing to keep, but they are on no plan, because a limit nothing
   meters can never be reached — advertising one is advertising a fiction.
   Putting either back is one number in the admin console, no deploy.

2. **Trial length is 14 days**, and the two warning emails fire at 3 days
   and 1 day before it charges (section 4).

3. **Deletion retention is 30 days**, configurable per environment rather
   than per customer.

**Genuinely still open:**

4. Confirm that anonymising, rather than hard-deleting, is acceptable to us
   legally. This is the one item on the original list that is a question for
   someone outside engineering, and it is unchanged.

---

## 14. How we get there

Each phase ends with something that can actually be shown.

| Phase | What it delivers | Demoable at the end |
|---|---|---|
| **0** | The plan and add-on table, once the value metric is decided | A pricing sheet |
| **1** | Signup, login, email verification, password reset, the admin login, workspaces, invitations, roles | Two people collaborating in a workspace |
| **2** | Plans, limits, add-ons, and the rules that gate features. Admin can create plans | Priced plans with real limits, and a read-only floor |
| **3** | Trials, all the workspace states, limit enforcement, every email. Staff grant plans by hand | **The complete product, sellable manually** |
| **4** | Real payments: checkout, renewals, plan changes, seat purchases, usage billing, failed-payment handling | Money arriving on its own |
| **5** | Admin console completed: impersonation, customer directory, overrides, revenue dashboard | Support and sales self-sufficient |
| **6** | Audit logs, notifications, exports, integrations | Enterprise-ready |

**The important thing about this order:** at the end of Phase 3 we have a
finished product that a human can sell and activate by hand. Payments in
Phase 4 remove the manual step — they are not what makes the product real.
This means we can put it in front of customers well before the billing
integration exists.

---

## 15. What to measure from day one

Signups. How many complete a workspace. How many start a trial. **Trial to
paid conversion** — the number this whole build exists to move. Seats added
after signup, as revenue. Monthly churn, and how much of it is voluntary
versus failed payments. Failed-payment recovery rate.

---

## 16. Known risks

| Risk | Why it matters | What we do about it |
|---|---|---|
| Money arrives on the provider's payout schedule | Cash flow assumption | Finance signs off before launch |
| No manual bank transfer | Closes off some local Indonesian buyers | Accept for now; revisit if it costs real deals |
| One trial per person | A genuine customer opening a real second workspace gets no trial | Staff can grant one by hand from the admin console |
| A customer cancels on the provider's page | Our records find out via notification, not immediately | We treat their notifications as authoritative and reconcile |
| Auto-charging trials | Generates disputes if warning emails fail | Trial-ending emails are a launch blocker, not a nice-to-have |
