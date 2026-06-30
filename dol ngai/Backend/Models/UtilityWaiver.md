# 🛡️ UtilityWaiver Model

- **File Path**: [UtilityWaiver.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/UtilityWaiver.php)
- **Table Name**: `utility_waivers`
- **Related Feature**: [[Features/Property]]

---

## 🎯 Purpose
The `UtilityWaiver` model handles exception scenarios where specific utilities are marked as waived (free/uncorrected charges) for an entire property, a specific unit, or a singular tenancy lease.

---

## ⚙️ Responsibilities & Behaviors
- **Conditional Scoping**: Can be configured broadly (property-wide) or narrowed down explicitly to a unit or rental record.
- **Query Resolution Priority**: Exposes a static helper method to determine if a utility is waived for a rental, prioritizing the most specific scope (rental ➔ unit ➔ property).
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/PropertyUtility|propertyUtility()]]** (BelongsTo): The utility being waived.
- **[[Backend/Models/Property|property()]]** (BelongsTo): Scoped property.
- **[[Backend/Models/Unit|unit()]]** (BelongsTo): Scoped unit.
- **[[Backend/Models/Rental|rental()]]** (BelongsTo): Scoped rental.
- **[[Backend/Models/User|createdBy()]]** (BelongsTo): Author of the waiver.

---

## 🛠️ Public Methods

### `isWaivedFor(int $propertyUtilityId, ?int $rentalId, ?int $unitId): bool` (Static)
- **Logic**: Performs a fallback check to see if a waiver exists matching:
  1. The specific property utility ID,
  2. The `waived = true` flag, and
  3. Narrowing scopes: a property-wide null record OR matching `unit_id` OR matching `rental_id`.

### `resolveLandlordId(): ?int`
- **Logic**: Traverses unit, property, and rental relations to extract the landlord ID.

---

## 🛡️ Business Rules
1. **Priority Hierarchy**: Specific rental or unit waivers override any general property-wide utility charge settings.
