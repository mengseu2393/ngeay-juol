# RentWise — Demo Seeder Specification (for an AI to implement)

> **Purpose.** This document tells another AI *exactly* what demo data to create so that
> **every feature** of RentWise is exercised end-to-end. It is a **specification, not code**.
> Read the whole thing, then produce a Laravel seeder (or set of seeders) that satisfies it.
>
> **Theme.** All people, places, phone numbers, and money are **Cambodian (Khmer)**. Use
> real Khmer names, real Cambodian provinces/districts/communes, Cambodian phone formats,
> and realistic USD/KHR amounts. Dual-currency (USD + Khmer Riel, ៛) must appear throughout.

---

## 0. Ground rules (read first)

1. **No auth context.** Seeders run from the CLI, so the landlord-scoping global scope
   no-ops. This means you **must set `landlord_id` explicitly on every landlord-owned row**
   (property, unit, rental, invoice, payment, utility, usage, waiver, maintenance, charge).
2. **Idempotent.** Use `firstOrCreate` / guards so re-running the seeder does not duplicate
   data or trip domain guards.
3. **Respect the domain guards** (see §12). The big ones:
   - Only **one Active rental per unit** at a time. Tenant history must be *sequential*
     (past tenants `Vacated`/`Expired`, then one current `Active`) — never overlapping.
   - Create a **login account per tenancy** via the room-account service, not by hand.
   - Build invoices through **`InvoiceBuilderService`** and record payments through the
     invoice ledger method (`recordPayment`) — never write `amount_paid`/status by hand.
4. **Gate it** behind an env flag (reuse `SEED_DEMO=true`) or run explicitly with
   `php artisan db:seed --class=...`. Do not force it into every migrate.
5. **Use the Enums**, never magic ints. Every enum and its cases are listed in §11 — you
   must produce at least one row for **each enum case** somewhere in the dataset.
6. **Use Khmer + English text.** Names in Latin transliteration are fine for the `name`
   column, but include Khmer script (ខ្មែរ) in notes, descriptions, chat messages, and
   maintenance titles to exercise the Khmer font / PDF rendering path.

---

## 1. Coverage checklist (the definition of "done")

The seeder is complete only when **all** of these are true. Treat this as the acceptance test.

- [ ] Every **role** exists and at least one user holds it (super_admin, admin/staff, landlord, tenant — plus any others the roles seeder defines).
- [ ] At least **2 landlords**: one "full-featured" landlord and one "simple mode" landlord (`is_simple_landlord = true`).
- [ ] At least **1 platform staff/admin** user and **1 super admin**.
- [ ] Every **PropertyType** enum case used by at least one property.
- [ ] Every **UnitStatus** case present across the units (Available, Occupied, Maintenance, Unavailable).
- [ ] Every **RentalStatus** case present (Active, Expired, Vacated) — via sequential tenant history.
- [ ] Every **BillingType** for utilities/charges (Metered, Flat, Shared).
- [ ] Every **ReadingType** for utility usages (Actual, Estimated, Fixed).
- [ ] At least one **UtilityWaiver** (a room waived from a utility for a period).
- [ ] **ChargeDefinition + ChargeRule** rows exercising overrides (amount override, currency override, scoped rule, effective date window).
- [ ] Every **InvoiceLineType** appears on some invoice (Rent, Utility, AdHoc).
- [ ] Every **InvoiceStatus** case present across invoices (Draft, Pending, Partial, Paid, Overdue, Cancelled).
- [ ] Every **PaymentMethod** used by at least one payment (Cash, BankTransfer, Card, MobilePayment, Cheque, Other).
- [ ] **Multi-currency**: invoices/payments in both USD and KHR; property settings carrying a USD↔KHR exchange rate; at least one payment in a currency different from the invoice's native currency.
- [ ] **Move-in billing modes** exercised via property settings: every **FirstMonthBillingMode** (FullMonth, Prorated, HalfMonth) used by at least one property; proration + deposit-upfront flags toggled.
- [ ] **Monthly billing** enabled on at least one property with a `next_invoice_date` set on its active rentals.
- [ ] Every **MaintenancePriority** and **MaintenanceStatus** present across maintenance requests, each with a thread of maintenance messages.
- [ ] **Chat**: every **ChatRoomType** (Direct, Group, Support) with participants and every **ChatMessageType** message (Text, Image, File, System).
- [ ] **Subscriptions (platform → landlord)**: every **PlanBillingModel** (Flat, PerUnit, Tiered) as a plan; every **PlanInterval** (Monthly, Quarterly, Yearly); every **SubscriptionStatus** across subscriptions (Pending, Trial, Active, Cancelled, Suspended); every **SubscriptionAccess** state; a **SubscriptionHistory** trail hitting every **SubscriptionAction**; **SubscriptionPayment** rows across every **SubscriptionPaymentStatus** (Pending, Succeeded, Failed, Refunded).
- [ ] **PDF/Excel paths reachable**: at least one fully-paid invoice with rent + utility + ad-hoc lines and Khmer notes, so A4/A5/thermal PDF and Excel export all have real data.
- [ ] **Tenant portal** works: at least one tenant with a `username` login linked to a unit's room account, with visible invoices.
- [ ] **Activity log / media**: create a couple of records with media attachments (e.g. lease agreement file, maintenance photo) if the media library is wired, so those relations aren't empty.
- [ ] **Demographics filled**: users have gender/dob/nationality/province/district/commune/village; tenant profiles have occupation, income, emergency contact, guarantor; landlord profiles have bank/payout details.

---

## 2. People & roles

Create Khmer people. Use these name pools (extend freely):

- **Given/family names**: Sok Dara, Chan Sophea, Vichea Kim, Lyhour Meas, Sreyna Chea,
  Rithy Pich, Bopha Nuon, Veasna Hong, Kanha Sok, Pisey Lim, Samnang Ouk, Theary Sen,
  Dara Kong, Sovann Mao, Channary Yim, Phalla Ros, Vannak Tep, Maly Heng, Visal Chhem,
  Sreypov Eng, Chenda Prak, Rattana Sam, Bunthoeun Chhay, Sothea Nop.

### 2.1 User accounts to create
| Role | Count | Notes |
|---|---|---|
| Super admin | 1 | Full platform access. |
| Platform staff / admin | 1–2 | Manages plans, subscriptions, landlord accounts, system settings. |
| Landlord (full) | 1 | Owns the big portfolio in §4. `is_simple_landlord = false`. Has a `LandlordProfile` with company + bank + payout details, `can_create_tenants = true`. |
| Landlord (simple mode) | 1 | `is_simple_landlord = true`. Smaller portfolio, minimal settings — to exercise the simplified flow. |
| Tenants | ≥ 12 | One login **per tenancy** (created via the room-account service). Some current, some past. |

**User fields to populate on everyone** (from the users table): `name`, `email`, `username`
(for portal/tenant logins), `phone_number` (Cambodian format, e.g. `012 345 678`, `092 …`,
`070 …`), `gender`, `dob`, `nationality` = `Khmer`/`Cambodian`, and location:
`province`, `district`, `commune`, `village`. Set `status` to cover **UserStatus** cases
(Active mostly; include one Inactive and one Suspended). Passwords: `password` is fine for demo.

**Locations to use** (mix across users/properties): Phnom Penh (Chamkarmon, Daun Penh,
Toul Kork, Sen Sok, Chbar Ampov), Siem Reap (Svay Dangkum, Sala Kamreuk), Battambang,
Sihanoukville (Kampong Seila), Kampot, Kandal (Ta Khmau).

### 2.2 Profiles
- **LandlordProfile** for each landlord: `company_name` (e.g. "Dara Rentals ដារ៉ាផ្ទះជួល"),
  `bank_name` (ABA / ACLEDA / Wing), `bank_account_name`, `bank_account_number`,
  `payout_details`, `can_create_tenants`.
- **TenantProfile** for each tenant: `id_card_number`, `occupation` (Teacher, Tuk-tuk driver,
  Garment worker, Shop owner, Bank staff, Student), `workplace`, `monthly_income` (USD,
  e.g. 200–1200), `emergency_contact_*`, `guarantor_*`, `move_in_date`, `notes` (in Khmer).

---

## 3. Platform: subscription plans (staff-managed)

Create **SubscriptionPlan** rows covering every billing model and interval:

| Plan | billing_model | interval | price | unit_price | max_units | max_properties | trial_days | grace_days |
|---|---|---|---|---|---|---|---|---|
| Free Trial | Flat | Monthly | 0 | – | small | 1 | 14 | 3 |
| Starter (Flat) | Flat | Monthly | e.g. $9 | – | e.g. 20 | 2 | 0 | 7 |
| Growth (Per-Unit) | PerUnit | Monthly | 0 base | e.g. $0.50/unit | 100 | 10 | 0 | 7 |
| Pro (Tiered) | Tiered | Yearly | e.g. $199 | tiered | large | unlimited-ish | 0 | 14 |
| Quarterly plan | any | Quarterly | … | … | … | … | 0 | 7 |

Set `slug`, `description` (Khmer + English), `features` (JSON list of feature flags),
`currency` (USD), `is_active`, `sort_order`. Make at least one plan **inactive** to prove the filter.

---

## 4. Landlord portfolio (properties → units → rentals)

For the **full landlord**, build a multi-property portfolio. Cover **every PropertyType**:

| Property | property_type | City / District | Layout |
|---|---|---|---|
| Riverside Residences | Apartment | Phnom Penh / Chamkarmon | 2 floors × 3 units |
| Sunrise Garden Villas | Villa | Siem Reap / Svay Dangkum | 2 floors × 2 units |
| City Center Condos | Condo | Phnom Penh / Daun Penh | 3 floors × 2 units |
| Angkor Family House | House | Siem Reap / Sala Kamreuk | 3 units |
| Central Market Shops | Commercial | Phnom Penh / Daun Penh | 4 shop units |
| Riverside Annex | Other | Kampot | 2 units |

**Property fields**: `name` (with Khmer subtitle in `description`), `property_type`,
`address_line`, `street`, `village`, `commune`, `district`, `city`, `postal_code`, `amenities`
(JSON: parking, wifi, security, elevator…).

**Units** (`room_number` like `A-101`, floors, `room_type` Studio / 1-Bedroom / 2-Bedroom /
Shop): set `rent_amount` and `rent_currency` (mix USD and KHR across units), `due_date`,
`description`, and `status` covering **every UnitStatus** (most Occupied, one Available,
one Maintenance, one Unavailable). Occupied units get an active rental; the room login lives
on `account_user_id` (set by the room-account service).

**Rentals** — this is where tenant history lives. For several units, create a **timeline**:
1–2 **past** tenancies (`Vacated` or `Expired`, with `end_date` in the past) followed by
**one current** `Active` tenancy. Never overlap. Populate the rich occupant + guarantor +
emergency fields (`occupant_name/phone/id_card/address/gender/dob/nationality/workplace`,
`guarantor_*`, `emergency_contact_*`), `monthly_rent` (+ currency), `security_deposit`
(+ currency), `lease_agreement`, `terms_conditions`, `signed_at`, `start_date`, `end_date`,
`next_invoice_date` (for monthly-billing units). **Each tenancy = one tenant login** created
through the room-account service.

Give the **simple landlord** a single small property with 2–3 units and one active tenant each,
minimal extras — to prove the simplified path renders.

---

## 5. Property settings (per property)

Create a **PropertySetting** for each property, and spread the options so every mode is covered:

- `currency` (USD for most, KHR for at least one), `usd_khr_exchange_rate` (~4100),
  `exchange_rate_date`, `exchange_rate_source` (`manual` / `api`), `exchange_rate_fetched_at`.
- `invoice_prefix` (e.g. `RW`, `RSR`, `SGV`), `due_day_of_month`, `invoice_due_days`,
  `late_fee`, `default_lease_months`, `deposit_policy`.
- **Move-in billing**: `first_month_billing_mode` — assign **FullMonth**, **Prorated**, and
  **HalfMonth** to different properties. Set `proration_cutoff_day`,
  `require_first_month_upfront`, `create_invoice_on_move_in`, `upfront_deposit_months`.
- **Monthly billing**: `monthly_billing_enabled = true` on at least one property (and its
  active rentals carry `next_invoice_date`); leave it off on another.
- Info/contacts: `water_billing_default`, `parking_info`, `insurance_info`, `caretaker_name`,
  `caretaker_phone` (Khmer name + Cambodian phone).

---

## 6. Utilities, charges & readings

### 6.1 Property utilities (`PropertyUtility`)
Per property, create utilities that cover **every BillingType**:
- **Electricity** — Metered, `unit_of_measure = kWh`, `rate ≈ 0.25` USD, provider `EDC`.
- **Water** — Metered (`m³`, provider `PPWSA`) on some properties; **Flat** on others.
- **Trash / Cleaning** — Flat, fixed per room (e.g. $2.5 or 10,000 ៛).
- **Shared master-meter** — Shared billing type (a total split across rooms).
Set `currency`, `provider`, `account_ref`, `is_active` (include one inactive), `notes`.

### 6.2 Charge definitions & rules (`ChargeDefinition` / `ChargeRule`)
Exercise the newer flexible-charge engine:
- **ChargeDefinition**: e.g. "Parking" (Flat), "Internet/Wifi" (Flat), "Service Fee" (Flat),
  plus one tied to a metered utility. Fields: `name`, `category`, `billing_type`,
  `default_amount`, `default_currency`, `unit_of_measure`, `is_active`, `notes`.
- **ChargeRule** (overrides): create rules that prove each override path —
  an **amount_override**, a **currency_override**, a **scoped** rule (`scope_type`/`scope_id`
  targeting a specific property/unit/rental), a rule with an **effective_from/effective_until**
  window (one currently effective, one future, one expired), and one linked to a
  `property_utility_id`. Set `state`, `reason`, `created_by_id`.

### 6.3 Utility usages (`UtilityUsage`) — cover every ReadingType
For metered utilities on occupied units, create monthly readings for the **last 3 months**:
- **Actual** readings with `old_reading` / `new_reading` / `amount_used`.
- At least one **Estimated** reading.
- At least one **Fixed** reading.
Set `landlord_id`, `recorded_by_id`, `reading_date`.

### 6.4 Utility waivers (`UtilityWaiver`)
Waive at least one utility for one room/rental for a period (`waived = true`), so the
invoice builder skips it. Set `property_utility_id`, `unit_id`, `rental_id`, `created_by_id`.

---

## 7. Invoices & payments (landlord → tenant)

**Always build invoices through `InvoiceBuilderService`** and record money through the invoice
**ledger** (`recordPayment`) so totals/status stay consistent. Across the dataset, produce
invoices covering **every InvoiceStatus** and lines covering **every InvoiceLineType**:

- **Draft** — an unfinished invoice.
- **Pending** — issued, unpaid, not yet due.
- **Partial** — a payment covering part of the amount.
- **Paid** — fully paid (this one drives the PDF/Excel demo: give it Rent + Utility + AdHoc
  lines and a Khmer `notes` block).
- **Overdue** — issued, past `due_date`, unpaid.
- **Cancelled** — cancelled invoice.

Populate the multi-currency columns: `usd_khr_rate`, `exchange_rate_source/date/fetched_at`,
`total_usd`, `total_khr`, `native_usd_total`, `native_khr_total`, `paid_usd`, `paid_khr`,
plus `invoice_number` (uses the property `invoice_prefix`), `period_start/end`, `issue_date`,
`due_date`.

**Invoice lines** (`InvoiceLine`): at least one invoice must contain **Rent + Utility + AdHoc**
lines together. The AdHoc line is a manual charge (e.g. "Repair contribution ជួសជុល", late fee).

**Payments** (`Payment`) — cover **every PaymentMethod** across the payment set (Cash,
BankTransfer, Card, MobilePayment (Wing/ABA Pay), Cheque, Other). For at least one payment,
pay in a **currency different from the invoice's native currency** and fill `amount_usd`,
`amount_khr`, `exchange_rate`, `exchange_rate_source`. Also set `paid_at`, `transaction_ref`,
`receipt_number`, `note`, `recorded_by_id`.

---

## 8. Maintenance

Create **MaintenanceRequest** rows for several occupied units, covering **every
MaintenancePriority** (Low, Medium, High, Urgent) and **every MaintenanceStatus** (Open,
InProgress, Resolved, Closed, Cancelled). Titles/descriptions in Khmer + English
(e.g. "ម៉ាស៊ីនត្រជាក់ខូច — Air conditioner not cooling", "Water leak in bathroom ទឹកលេច").
Link `tenant_id`, `landlord_id`, `property_id`, `unit_id`, `rental_id`.

For each request add a thread of **MaintenanceMessage** rows (tenant reports → landlord
replies → status-change note), so the conversation view isn't empty. Attach a photo via the
media library on at least one request if media is wired.

---

## 9. Chat / messaging

Create **ChatRoom** rows covering **every ChatRoomType**:
- **Direct** — landlord ↔ a tenant.
- **Group** — landlord + multiple tenants of one property.
- **Support** — tenant ↔ platform support.

Add participants (`chat_room_participants`) and **ChatMessage** rows covering **every
ChatMessageType**: **Text** (Khmer + English), **Image**, **File**, and a **System** message
(e.g. "អ្នកជួលបានចូលរួម — Tenant joined"). Set `created_by_id`, timestamps, and leave one
room `archived_at` to prove the archived filter. Rooms may be `related_type/related_id`-linked
to a rental or maintenance request.

---

## 10. Platform subscriptions (staff → landlord billing)

This is **separate** from tenant rent. For each landlord, wire a subscription lifecycle so
every subscription enum is hit.

- **Subscription** (`Subscription`): assign plans so **every SubscriptionStatus** appears
  across landlords — Pending, Trial (with `trial_ends_at`), Active, Cancelled (with
  `cancelled_at` + `cancellation_reason`), Suspended (with `suspended_at` + `suspension_reason`).
  Fill `billing_model`, `interval`, `price`, `unit_price`, `max_units`, `max_properties`,
  `features`, `currency`, `starts_at`, `ends_at`, `grace_ends_at`, `auto_renew`,
  `current_unit_count`. Make the access level span **every SubscriptionAccess** (Full,
  PastDue, ReadOnly, Revoked) — e.g. an Active-Full landlord, a grace-period PastDue one,
  a ReadOnly one, and a Revoked one.
- **SubscriptionHistory**: build an audit trail that includes **every SubscriptionAction**
  (Started, Renewed, Upgraded, Downgraded, PlanChanged, Cancelled, Reactivated, Suspended,
  Extended, Shortened, TrialStarted).
- **SubscriptionPayment**: rows covering **every SubscriptionPaymentStatus** (Pending,
  Succeeded, Failed, Refunded). Fill `amount`, `currency`, `method`, `paid_at`,
  `covers_from/covers_to`, `gateway`, `gateway_transaction_id`, `gateway_ref`,
  `receipt_number`, `note`, `recorded_by_id`.

---

## 11. Enum reference (produce ≥1 row per case)

> These are the actual enums in `app/Enums`. Every case below must appear somewhere.

- **UserStatus**: Inactive(0), Active(1), Suspended(2)
- **PropertyType**: Apartment, House, Condo, Villa, Commercial, Other
- **UnitStatus**: Available, Occupied, Maintenance, Unavailable
- **RentalStatus**: Active, Expired, Vacated
- **BillingType**: Metered, Flat, Shared
- **ReadingType**: Actual, Estimated, Fixed
- **InvoiceStatus**: Draft, Pending, Partial, Paid, Overdue, Cancelled
- **InvoiceLineType**: Rent, Utility, AdHoc
- **PaymentMethod**: Cash, BankTransfer, Card, MobilePayment, Cheque, Other
- **FirstMonthBillingMode**: FullMonth, Prorated, HalfMonth
- **MaintenancePriority**: Low, Medium, High, Urgent
- **MaintenanceStatus**: Open, InProgress, Resolved, Closed, Cancelled
- **ChatRoomType**: Direct, Group, Support
- **ChatMessageType**: Text, Image, File, System
- **PlanBillingModel**: Flat, PerUnit, Tiered
- **PlanInterval**: Monthly, Quarterly, Yearly
- **SubscriptionStatus**: Pending, Trial, Active, Cancelled, Suspended
- **SubscriptionAccess**: Full, PastDue, ReadOnly, Revoked
- **SubscriptionAction**: Started, Renewed, Upgraded, Downgraded, PlanChanged, Cancelled, Reactivated, Suspended, Extended, Shortened, TrialStarted
- **SubscriptionPaymentStatus**: Pending, Succeeded, Failed, Refunded

---

## 12. Domain guards & services (do not fight these)

- **One active rental per unit.** Enforced via the unit's active-tenancy guard. Sequence
  tenancies; close the old one (`Vacated`/`Expired` + `end_date`) before opening the next.
- **Room accounts / logins.** Use the **RoomAccountService** (`createForRental`) to mint a
  login per tenancy instead of setting `account_user_id` / tenant users by hand.
- **Invoices.** Use **InvoiceBuilderService::create([...])** with `rental`, `period_start`,
  `period_end`, and the relevant `usages`. It computes lines, totals, and currency columns.
- **Payments.** Use the invoice's **ledger method** (`recordPayment([...])`) so `amount_paid`,
  `paid_usd/khr`, and `payment_status` recompute automatically. Never write those columns raw.
- **Landlord scope.** In CLI there's no auth user, so always pass `landlord_id` explicitly.
- **Roles/permissions.** Roles come from the existing `RolesAndPermissionsSeeder`
  (Shield-style `{action}_{resource}`). Call it (or depend on it) before assigning roles;
  use `syncRoles([...])`.

---

## 13. Suggested structure & run instructions

Organize as focused seeders called from a parent, e.g.:

```
KhmerDemoSeeder (orchestrator)
 ├─ RolesAndPermissionsSeeder      (existing — reuse)
 ├─ PlatformStaffSeeder            (super admin + staff + system settings)
 ├─ SubscriptionPlanSeeder         (all plans)
 ├─ KhmerLandlordPortfolioSeeder   (landlords, properties, units, rentals, settings, utilities, charges)
 ├─ BillingSeeder                  (readings, waivers, invoices, lines, payments)
 ├─ MaintenanceSeeder              (requests + messages)
 ├─ ChatSeeder                     (rooms, participants, messages)
 └─ SubscriptionLifecycleSeeder    (subscriptions, histories, payments)
```

Run with:

```bash
SEED_DEMO=true php artisan migrate:fresh --seed
# or a specific class
php artisan db:seed --class=Database\\Seeders\\KhmerDemoSeeder
```

**Before you finish, self-check against §1 (the coverage checklist) and §11 (every enum
case).** If any box is unchecked, the seeder is not done.
