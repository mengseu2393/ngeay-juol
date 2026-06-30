# 💡 Decision: FIFO Tenancy Scoping

---

## 🎯 Context
In property management, a room or unit can only contain one active occupant at any point in time. The system must prevent landlords from registering overlapping leases on the same unit.

---

## ⚖️ Evaluation & Choices
- **FIFO Tenancy Scoping** ensures that leases are sequential (e.g. Tenant A rents Jan-May, then Tenant B rents Jun-Dec) and never overlap:
  - **DB Generated Column Constraint**: Implements a generated column `active_unit_id` (which translates to `unit_id` when the lease status is `Active`, else `NULL`) and places a unique constraint on it. This enforces safety at the SQL level.
  - **Application Service Guard**: Implements `TenancyService::hasActiveTenancy(...)` and `TenancyService::hasOverlap(...)` checks inside model boot methods to intercept overlapping dates and throw user-friendly error messages before database index failures occur.
