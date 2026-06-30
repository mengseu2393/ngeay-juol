# RentWise — Subscription & Billing Module

## Domain Boundary

This module handles **platform → landlord** billing. It is **separate** from the existing landlord → tenant billing (Invoice / Payment models). Do not reference or reuse tenant-billing tables here.

---

## 1. Pricing Model

**Recommended: Tiered plans (by unit/room count).**

| Model | Value |
|---|---|
| Starter (≤10 units) | $10/mo |
| Growth (≤50 units) | $25/mo |
| Pro (≤200 units) | $60/mo |
| Enterprise (unlimited) | Custom |

The schema stores `max_units` and a `unit_price` column to support per-unit overage in the future without a migration. Plans define feature flags in a JSON column (`features`) — gating module access per tier.

---

## 2. Subscription Lifecycle

### State machine

```
                               ┌──────────┐
                     assign    │ Pending  │
                    ┌─────────►│          │
                    │          └──────────┘
                    │
              ┌─────┴──────┐         ┌─────────┐
              │   Trial    │◄───────►│ Active  │
              └────────────┘  start  └────┬────┘
                                          │
                              ┌───────────┼───────────┐
                              │           │           │
                         ┌────▼──┐  ┌─────▼─────┐  ┌──▼────────┐
                         │ Past  │  │ Cancelled │  │ Suspended │
                         │ Due   │  │ (period   │  │ (immediate│
                         └────┬──┘  │  end)     │  │  revoke)  │
                              │     └───────────┘  └───────────┘
                         ┌────▼──┐
                         │Expired│
                         └───────┘
```

### Effective access (computed, not stored)

| State | Access level | Behavior |
|---|---|---|
| Active / Trial | `full` | Everything enabled |
| Past Due | `past_due` | Full access + dunning banner |
| Expired (≤retention window) | `read_only` | View & export only, no edits |
| Expired (>retention) | `revoked` | Panel access denied |
| Suspended | `revoked` | Panel access denied |

### Cancellation rules

- **Landlord-initiated:** cancels at period end (`cancelled_at` set, `ends_at` unchanged). Runs full-access until `ends_at`.
- **Admin-initiated (cancel_immediate):** access revoked immediately.
- **Suspension (admin):** immediate revoke, no date change. Reactivation recovers full access.

### Grace period

After `ends_at` passes, a configurable grace window (plan-level, falls back to `Setting::get('grace_days', 7, 'billing')`). During grace: `past_due` — full access with prominent warnings + dunning emails. After grace: `expired`.

### Retention window

After expiry, landlord has 90 days (configurable via `Setting`) to pay / export data. After that: `revoked`. Data is never hard-deleted — only access is denied.

### Tenant portal during landlord expiry

Tenants can **always** log in and pay rent, regardless of landlord's subscription status. Tenant data is theirs, not the landlord's.

---

## 3. Database Schema

### `subscription_plans`

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| name | string | Display name |
| slug | string | Unique, for code references |
| description | text, nullable | |
| billing_model | tinyint, enum | PlanBillingModel (Flat=1 / PerUnit=2 / Tiered=3) |
| interval | tinyint, enum | PlanInterval (Monthly=1 / Quarterly=2 / Yearly=3) |
| price | decimal(12,2) | Base price per interval |
| unit_price | decimal(12,2), nullable | Per-unit overage price |
| max_units | int, nullable | Tier ceiling |
| max_properties | int, nullable | |
| trial_days | smallint, default 0 | |
| grace_days | smallint, default 0 | 0 = use platform setting |
| features | json, nullable | `{ "maintenance": true, "api": false }` |
| currency | string(3), default 'USD' | |
| is_active | boolean, default true | Retire plan (existing subs keep running) |
| sort_order | smallint, default 0 | |
| timestamps + softDeletes | | |

### `subscriptions` (current state — 1 per landlord)

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| landlord_id | FK → users | Denormalized, scoped by LandlordScope |
| plan_id | FK → subscription_plans | |
| status | tinyint, enum | SubscriptionStatus enum |
| billing_model | tinyint | Snapshot of plan terms |
| interval | tinyint | Snapshot |
| price | decimal(12,2) | Snapshot |
| unit_price | decimal(12,2), nullable | Snapshot |
| max_units | int, nullable | Snapshot |
| max_properties | int, nullable | Snapshot |
| features | json, nullable | Snapshot |
| currency | string(3) | Snapshot |
| starts_at | date | |
| ends_at | date | Current period end |
| grace_ends_at | date, nullable | ends_at + grace_days |
| trial_ends_at | date, nullable | |
| auto_renew | boolean, default true | |
| cancelled_at | datetime, nullable | |
| cancellation_reason | text, nullable | |
| suspended_at | datetime, nullable | |
| suspension_reason | text, nullable | |
| current_unit_count | int, nullable | Cached nightly count |
| timestamps + softDeletes | | |
| UNIQUE | (landlord_id) WHERE deleted IS NULL | One active subscription |

### `subscription_histories` (append-only ledger)

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| subscription_id | FK → subscriptions | |
| landlord_id | FK → users | Denormalized for scoping |
| plan_id | FK → subscription_plans | |
| action | tinyint, enum | SubscriptionAction (Renewed / Upgraded / …) |
| period_start | date | |
| period_end | date | |
| price | decimal(12,2) | Effective price this period |
| unit_count | int, nullable | |
| amount_charged | decimal(12,2), nullable | |
| meta | json, nullable | `{ old_plan_id, reason, actor }` |
| note | text, nullable | |
| created_by_id | FK → users, nullable | Who performed the action |
| timestamps | | |

### `subscription_payments` (platform-billing receipts)

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| subscription_id | FK → subscriptions | |
| landlord_id | FK → users | Denormalized |
| plan_id | FK → subscription_plans | Snapshot |
| amount | decimal(12,2) | |
| currency | string(3) | |
| method | tinyint, enum | Reuses existing PaymentMethod enum |
| status | tinyint | SubscriptionPaymentStatus |
| paid_at | datetime, nullable | |
| covers_from | date | Period this payment covers |
| covers_to | date | |
| gateway | string, nullable | 'manual', 'stripe', etc. |
| gateway_transaction_id | string, nullable | |
| gateway_ref | string, nullable | |
| receipt_number | string, nullable, unique | |
| note | text, nullable | |
| recorded_by_id | FK → users, nullable | |
| timestamps | | |
| UNIQUE | (gateway, gateway_transaction_id) | Idempotency |

---

## 4. Service Layer (`SubscriptionService`)

Single choke point for all state transitions.

### Public methods

```
assign(User $landlord, SubscriptionPlan $plan, array $opts = []): Subscription
renew(Subscription $sub, array $paymentData): SubscriptionPayment|false
changePlan(Subscription $sub, SubscriptionPlan $newPlan, bool $immediate = false): void
cancel(Subscription $sub, ?string $reason, bool $immediate = false): void
suspend(Subscription $sub, string $reason): void
reactivate(Subscription $sub): void
extend(Subscription $sub, int $days, string $reason): void
shorten(Subscription $sub, int $days, string $reason): void
recomputeUnitCount(Subscription $sub): int
effectiveAccess(User $user): SubscriptionAccess
isFeatureEnabled(User $user, string $feature): bool
assertWithinUnitCap(User $user, int $newCount): void
```

### Design rules

- Every mutation writes to both `subscriptions` AND `subscription_histories` in the same DB transaction.
- `effectiveAccess()` is the **only** place date logic is evaluated. Results cached per-request.
- Plan terms are **snapshotted** on assignment/change; editing a plan never alters an active subscription.
- Payments are **idempotent** on `(covers_from, covers_to, gateway)` — calling `renew()` twice with the same payment data is safe.

---

## 5. Enforcement

### 5a. Middleware (`EnsureActiveSubscription`)

Registered on the **landlord panel** only (`authMiddleware` list). Checks `effectiveAccess()`:

- `revoked` → redirect to login with "subscription expired" message.
- `read_only` → allow access, set `request('_subscription_readonly')` flag for views.
- `past_due` → allow access, set dunning banner flag.
- `full` → pass through.

**Staff bypass:** super_admin / support users are never blocked (they need access to support landlords).

### 5b. Feature gates

Plan `features` JSON → `Gate::define('feature.X', fn(User $user) => SubscriptionService::isFeatureEnabled(...))`. Used by Filament resource `canAccess()`, navigation visibility, and policies.

### 5c. Unit-cap enforcement

`Unit` create/update policy calls `SubscriptionService::assertWithinUnitCap()`. Blocks creation when `count(units) >= max_units`.

---

## 6. Scheduled Tasks

Registered in `routes/console.php`. Run daily at 00:30:

| Command | What it does |
|---|---|
| `subscriptions:expiry-sweep` | Marks past-due → expired after grace. Revokes after retention. |
| `subscriptions:auto-renew` | Attempts gateway payment for `auto_renew=true` subs hitting `ends_at`. |
| `subscriptions:recompute-units` | Caches `current_unit_count` for all active subs. |
| `subscriptions:dunning` | Sends reminder emails at -7/-3/-1/0 days. |

---

## 7. Super Admin Features (`/admin` panel)

Navigation group: **Billing** (between Administration and other groups).

### SubscriptionPlanResource

| Action | Details |
|---|---|
| List | Sortable table: name, interval, price, max_units, is_active badge |
| Create | Form with billing_model → conditional fields (unit_price, max_units) |
| Edit | Same form. Retire via `is_active = false` (prevents new assignments) |
| Delete | Only if no subscriptions reference it (FK restrictOnDelete) |

### SubscriptionResource

| Action | Details |
|---|---|
| List | landlord, plan, status badge, ends_at, grace_ends_at, units-used/cap, MRR |
| Filters | status, plan, expiring-within, past-due |
| Assign | Select landlord + plan (auto-creates with Pending/Active status) |
| View | Infolist with subscription details + history relation manager + payments relation manager |
| Renew | Record a manual payment → extends ends_at |
| Extend / Shorten | Mutate ends_at (reason required, logged to history) |
| Cancel | Period-end (default) or immediate |
| Suspend / Reactivate | |
| Change Plan | Upgrade immediate or downgrade at period end |

### SubscriptionPaymentResource

Read-only receipt ledger + "Record payment" action for offline payments.

### Dashboard widgets

- MRR counter
- Active / past-due / expiring-7d stat cards
- Expiring-soon table

---

## 8. Landlord Experience (`/landlord` panel)

### BillingPage (`/landlord/billing`)

Single-record page (the landlord's own subscription):

- Current plan name + price per interval
- Expiry date with countdown (color: green ≥14d, amber ≤7d, red ≤0)
- Status badge (Active / Past Due / Expired)
- Usage bar: `units_used / max_units` + properties count
- Auto-renew toggle
- Payment history table
- Receipt download buttons
- **Actions:** Renew (record payment via modal), Upgrade (plan picker → prorated), Downgrade (scheduled at period end)
- **Banners:** persistent warning when past-due or read-only

### Dashboard widget

Compact stat card showing subscription status + days to expiry. Visible on the landlord dashboard.

---

## 9. Scalability & Best Practices

1. **Domain isolation** — separate tables from tenant billing. No shared state.
2. **Plan versioning via snapshot** — `subscriptions` carries frozen plan terms. Editing a plan never touches active subs.
3. **Append-only history ledger** — `subscription_histories` is the single source of truth for revenue recognition + disputes.
4. **Intent vs. effective** — stored `status` says what we *intend*; computed `effectiveAccess()` says what we *enforce*. No cron-fighting-date bugs.
5. **Gateway abstraction** — `interface PaymentGateway` with `ManualGateway` (admin records offline) and `StripeGateway` (future). Webhooks verified + idempotent.
6. **One enforcement primitive** — `SubscriptionService::effectiveAccess()` cached per-request. No sprawl.
7. **Feature gates via Gate** — adding a feature = 1 Gate definition, not a migration.
8. **Configurable settings via `Setting` model** — grace_days, retention_days, platform currency. No magic numbers.
9. **Idempotent scheduled jobs** — DB transactions prevent double-charging.
10. **Tenants always win** — tenant portal never blocked by landlord's subscription status.
