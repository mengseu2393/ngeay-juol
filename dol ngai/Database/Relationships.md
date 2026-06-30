# 💾 Database Relationships & Constraints

This document details the database-level design choices, relationships, foreign key constraints, and performance indexes used in the RentWise project.

---

## 🗄️ Core Database Boundaries

### 1. Landlord ➔ Property ➔ Unit (One-to-Many Chain)
- Every property belongs to a `landlord_id` pointing to `users(id)`.
- Every unit has a foreign key `property_id` pointing to `properties(id)` with `cascadeOnDelete()`.
- Units also contain a denormalized `landlord_id` pointing to `users(id)` for immediate scoping (see [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]]).

### 2. Unit ➔ Rental (Tenancy History)
- Tenancies are sequential. Each unit holds a history of rentals (`tenant_id` pointing to `users(id)`).
- **Active Tenancy Index Rule**: To enforce the constraint "one active tenancy per unit" at the database level:
  - MySQL/SQLite databases utilize a generated column `active_unit_id` (which equals `unit_id` when status is `Active`, else `NULL`).
  - A unique index is created on `active_unit_id`. Since unique indexes permit multiple NULLs, this allows unlimited inactive rentals but strictly limits active rentals to one per unit.

### 3. Invoice Ledger (Invoice ➔ Lines ➔ Payments)
- Invoices are linked to rentals (`rental_id`), properties (`property_id`), and tenants (`tenant_id`).
- `invoice_lines` are billed against an invoice (`invoice_id`). On delete, they cascade.
- `payments` are logged against `invoice_id`. Deleted payments cascade.
- `amount_paid` on the invoice is **never** written directly by developers. It is calculated by a sum query on payments (preventing drift).

### 4. Property Utilities & Readings
- Utilities (`property_utilities`) are linked to properties (`property_id`).
- Utility usage (`utility_usages`) links a unit (`unit_id`), rental (`rental_id`), and the specific property utility (`property_utility_id`).
- Waivers (`utility_waivers`) are mapped selectively to properties, units, or rentals.

---

## 🏎️ Indexes for Query Performance

- **Composite Scoping Keys**: Frequently filtered fields have composite indexes:
  - `invoices(landlord_id, payment_status)`
  - `rentals(unit_id, status)`
  - `utility_usages(unit_id, reading_date)`
- **Foreign Key Indexing**: Every foreign key column has an index added explicitly (standard Laravel convention) to accelerate join performance during billing runs.
