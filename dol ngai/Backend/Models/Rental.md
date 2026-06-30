# 🔑 Rental Model

- **File Path**: [Rental.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Rental.php)
- **Table Name**: `rentals`
- **Related Feature**: [[Features/Rental]]

---

## 🎯 Purpose
The `Rental` model represents a lease/tenancy agreement between a landlord and an occupant (Tenant) for a specific [[Backend/Models/Unit|Unit]] over a defined time range.

---

## ⚙️ Responsibilities & Behaviors
- **Tenant Lease Metadata**: Holds rent rates, security deposit, start/end dates, references to signed agreements, and occupant details.
- **Unit Occupancy Lock-Step**:
  - `saved`: Marks the associated room status as `Occupied` if the rental is `Active` (calls `occupyUnit()`).
  - `wasChanged('unit_id')`: If the tenancy is moved to a new unit, automatically marks the previous room as `Available` (calls `freeUnitIfVacant()`).
  - `deleted`: Marks the room as `Available` if no other active leases are present.
- **User Account Syncing**: When a rental status changes, it automatically activates or deactivates the tenant's [[Backend/Models/User|User]] account (avoiding deactivation for shared room accounts).
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/User|tenant()]]** (BelongsTo): The tenant user account.
- **[[Backend/Models/Unit|unit()]]** (BelongsTo): The rented unit/room.
- **[[Backend/Models/Property|property()]]** (BelongsTo): The parent property.
- **[[Backend/Models/Invoice|invoices()]]** (HasMany): Scoped invoices created under this lease.
- **[[Backend/Models/UtilityUsage|utilityUsages()]]** (HasMany): Recorded utility readings.
- **[[Backend/Models/MaintenanceRequest|maintenanceRequests()]]** (HasMany): Issues raised during this lease.

---

## 🛠️ Public Methods & Algorithms

### `isActive(): bool`
- **Logic**: Returns whether the lease is currently in `Active` status.

### `occupyUnit(): void`
- **Logic**: If the unit's status is currently `Available`, it changes it to `Occupied` upon lease activation.

### `freeUnitIfVacant(int $unitId, ?int $excludeRentalId = null): void` (Protected)
- **Logic**: Queries the database to verify if any active leases remain for the unit. If none remain, flips the unit status from `Occupied` to `Available`.

### `resolveLandlordId(): ?int`
- **Logic**: Grabs the `landlord_id` associated with the unit.

---

## 🛡️ Business Rules
1. **FIFO Tenancy Scoping**: A unit can only have one `Active` lease at a time. Ending or vacating a lease must be completed before starting a new one.
2. **Denormalization Sync**: The `property_id` is automatically synced from the associated unit's `property_id` during creation to speed up queries.
