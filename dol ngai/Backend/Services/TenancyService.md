# ⚙️ TenancyService

- **File Path**: [TenancyService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/TenancyService.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
Encapsulates date validations and scheduling checks to enforce tenancy rules.

---

## ⚙️ Responsibilities & Behaviors
- **Overlap Validation**: Ensures no unit has overlapping active tenancies.
- **Active Tenancy Check**: Enforces the single-active tenancy constraint per room.

---

## 🛠️ Public Methods

### `hasOverlap(int $unitId, CarbonInterface|string $startDate, ...): bool` (Static)
- **Logic**: Evaluates if another active lease overlaps the given start/end date range for the target unit.

### `hasActiveTenancy(int $unitId, ?int $excludeRentalId = null): bool` (Static)
- **Logic**: Checks if any other rental record on the unit is currently set to `Active` status, bypassing date checks.

---

## 🛡️ Business Rules
1. **Single Occupant Rule**: A physical room can only contain one active lease. This service enforces this at the application layer, backing up the SQLite/MySQL unique index constraints.
