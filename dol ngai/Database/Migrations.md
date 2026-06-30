# 💾 Migrations Map

RentWise migrations define the schema structure, generated columns, foreign keys, and indexes.

---

## 🔀 Migration History Summary

### 1. Foundation Tables (Users, Sessions, Jobs)
- `0001_01_01_000000_create_users_table.php`: Defines authentication, passwords, names, and emails.
- `0001_01_01_000001_create_cache_table.php`: Cache keys database driver support.
- `0001_01_01_000002_create_jobs_table.php`: Queue and job batch tracking.

### 2. RBAC & Spatie Logging
- `2026_06_21_023350_create_permission_tables.php`: Spatie roles, permissions, and user role-maps.
- `2026_06_21_023351_create_activity_log_table.php`: Spatie auditing logs.

### 3. Business Core
- `2026_06_21_100001_create_landlord_profiles_table.php`: Extension properties for landlords.
- `2026_06_21_100002_create_tenant_profiles_table.php`: Extension properties for tenants.
- `2026_06_21_100003_create_properties_table.php`: Properties metadata (address, amenities).
- `2026_06_21_100005_create_units_table.php`: Individual rooms configuration.
- `2026_06_21_100006_create_rentals_table.php`: Leases tracking.
- `2026_06_21_100008_create_utility_prices_table.php` & `2026_06_21_100009_create_utility_usages_table.php`: Readings, rates, and parameters.
- `2026_06_21_100011_create_invoices_table.php`: Invoice records.
- `2026_06_21_100012_create_invoice_lines_table.php`: Itemized line items.
- `2026_06_21_100013_create_payments_table.php`: Payment transactions.

### 4. SaaS Subscription Management
- `2026_06_28_100001_create_subscription_plans_table.php`: Platform pricing matrices.
- `2026_06_28_100002_create_subscriptions_table.php`: Contracts linking landlords to plans.
- `2026_06_28_100003_create_subscription_histories_table.php`: Subscription state audit log.
- `2026_06_28_100004_create_subscription_payments_table.php`: Platform SaaS invoices.

---

## ⚡ Key Schema Optimizations

### 1. The `active_unit_id` Constraint
To prevent overlapping active tenancies, the `rentals` table uses a generated column:
```php
$table->unsignedBigInteger('active_unit_id')
    ->storedAs("case when status = 'active' then unit_id else null end");
$table->unique('active_unit_id');
```
This forces database-level validation backing up the [[Backend/Services/TenancyService|TenancyService]] checks.

### 2. Denormalized Scoping Keys
- Invoices denormalize `property_id` and `landlord_id` to speed up Filament index loading without requiring multi-layer joins.
- Scopes automatically inject constraints on these columns (see [[Backend/Models/Scopes/LandlordScope|LandlordScope]]).
