# 🔌 UtilityUsage Model

- **File Path**: [UtilityUsage.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/UtilityUsage.php)
- **Table Name**: `utility_usages`
- **Related Feature**: [[Features/Property]]

---

## 🎯 Purpose
The `UtilityUsage` model stores the utility consumption readings (e.g. water or electricity meter inputs) for a given [[Backend/Models/Unit|Unit]] and [[Backend/Models/Rental|Rental]] period.

---

## ⚙️ Responsibilities & Behaviors
- **Meter Recording**: Records old reading, new reading, reading type (e.g., Estimated, Actual), reading date, and the total computed usage (`amount_used`).
- **Billing Calculations**: Feeds information directly to the invoicing module for metered billing calculations.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/PropertyUtility|propertyUtility()]]** (BelongsTo): The specific utility rate definition.
- **[[Backend/Models/Unit|unit()]]** (BelongsTo): The unit whose meter was read.
- **[[Backend/Models/Rental|rental()]]** (BelongsTo): The tenancy lease active during the reading.
- **[[Backend/Models/User|recordedBy()]]** (BelongsTo): The manager who recorded the reading.
- **[[Backend/Models/InvoiceLine|invoiceLine()]]** (HasOne): The billed invoice line associated with this usage.

---

## 🛠️ Public Methods

### `resolveLandlordId(): ?int`
- **Logic**: Resolves landlord ID based on the associated unit's `landlord_id`.

---

## 🛡️ Business Rules
1. **Usage Precision**: Quantity fields (`old_reading`, `new_reading`, and `amount_used`) are cast to `decimal:3` to allow precise metrics (e.g., cubic meters of water).
2. **Waiver Check**: Supports an `is_waived` property. If checked, billing lines are generated with a waived value.
