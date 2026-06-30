# ⚙️ SubscriptionService

- **File Path**: [SubscriptionService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/SubscriptionService.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
Provides central workflow enforcement for landlord SaaS plans, trial periods, subscription billing, and resource restrictions.

---

## ⚙️ Responsibilities & Behaviors
- **Plan Transitions**: Handlers for plan assignment, renewals, upgrades, downgrades, cancellations, and extensions.
- **Resource Enforcement (Cap Assertions)**:
  - Asserts that a landlord doesn't exceed their active unit limit (max rooms).
- **Access Guarding**: Evaluates the effective access level of a landlord profile, determining if they have `Full`, `PastDue` (grace period), `ReadOnly` (retention phase), or `Revoked` access.
- **History Tracking**: Keeps audit records of subscription updates in the `subscription_histories` table.

---

## 🛠️ Public Methods & Algorithms

### `assign(User $landlord, SubscriptionPlan $plan, array $opts = []): Subscription`
- **Logic**: Registers a landlord to a new SaaS plan. Enforces trial days and calculates future period dates.

### `renew(Subscription $sub, array $paymentData): SubscriptionPayment`
- **Logic**: Records a successful payment ledger entry and moves the subscription period boundaries forward.

### `effectiveAccess(User $user): SubscriptionAccess`
- **Logic**: Main access check:
  - Platform staff bypass ➔ `Full`
  - Suspended status ➔ `Revoked`
  - Active/trial period ➔ `Full`
  - Grace period ➔ `PastDue`
  - Retention window (typically 90 days post-end) ➔ `ReadOnly`
  - Cutoff ➔ `Revoked`

### `assertWithinUnitCap(User $user, int $newCount = 1): void`
- **Logic**: Throws validation exceptions if adding rooms puts the landlord over their active plan cap.

---

## 🛡️ Business Rules
1. **Uniqueness Guard**: A landlord can only hold one subscription record at any time.
2. **Grace Period**: Calculated per plan or defaults to global settings (e.g. 7 days).
3. **Retention Period**: Expired accounts enter a read-only retention phase (typically 90 days) before access is fully blocked.
