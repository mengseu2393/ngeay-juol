# 🛏️ Unit Model

- **File Path**: [Unit.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Unit.php)
- **Table Name**: `units`
- **Related Feature**: [[Features/Property]]

---

## 🎯 Purpose
The `Unit` model represents an individual physical room or unit within a [[Backend/Models/Property|Property]]. It is the container for active tenancies, utility meters, and room-specific billing.

---

## ⚙️ Responsibilities & Behaviors
- **Tenant Room Assignment**: Maps to active rentals and historical leases.
- **SaaS Subscription Guarding**:
  - `creating`: Validates that the landlord is within their SaaS plan unit cap limit via `SubscriptionService::assertWithinUnitCap()`.
  - `created` / `deleted`: Triggers `SubscriptionService::recomputeUnitCount()` to update active unit counters.
- **Room Account Assignment**: Links to a permanent login user profile (`account_user_id`) assigned to the room.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/Property|property()]]** (BelongsTo): Parent property.
- **account()** (BelongsTo): The permanent, room-specific [[Backend/Models/User|User]] account (used for tenant logins).
- **[[Backend/Models/Rental|rentals()]]** (HasMany): Leases associated with this room.
- **[[Backend/Models/Rental|activeRental()]]** (HasOne): The current active lease (status is `Active`).
- **[[Backend/Models/UtilityUsage|utilityUsages()]]** (HasMany): Meter readings (electricity, water) recorded for this room.
- **[[Backend/Models/Invoice|invoices()]]** (HasManyThrough): Invoices issued to this unit through its rentals.

---

## 🛠️ Public Methods

### `resolveLandlordId(): ?int`
- **Logic**: Resolves the property owner's landlord ID. Used by the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] boot method during creation.

---

## 🛡️ Business Rules
1. **FIFO Tenancy Scoping**: A unit can only have ONE `Active` rental record at a time. This is validated on rental saving (see [[Features/Rental]]).
2. **Subscription Caps**: Landlords on the Basic plan cannot exceed their active room limit (enforced at model creation).
