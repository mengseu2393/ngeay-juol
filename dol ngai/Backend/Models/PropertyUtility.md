# ⚙️ PropertyUtility Model

- **File Path**: [PropertyUtility.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/PropertyUtility.php)
- **Table Name**: `property_utilities`
- **Related Feature**: [[Features/Property]]

---

## 🎯 Purpose
The `PropertyUtility` model represents a utility configuration (e.g. Electricity, Water, Trash collection) scoped to a single [[Backend/Models/Property|Property]]. It establishes the rate and billing calculation rules for that property.

---

## ⚙️ Responsibilities & Behaviors
- **Scoped Billing Rates**: Defines the billing method (`billing_type`) like Flat rate (fixed per room), Metered (usage × rate), or Shared (split master meter).
- **Provider References**: Tracks the utility provider name and official account reference identifier.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/Property|property()]]** (BelongsTo): Scoped property.
- **[[Backend/Models/UtilityUsage|usages()]]** (HasMany): Historical meter readings associated with this utility.
- **[[Backend/Models/UtilityWaiver|waivers()]]** (HasMany): Associated utility waivers.

---

## 🛠️ Public Methods

### `resolveLandlordId(): ?int`
- **Logic**: Resolves the property owner's landlord ID.

---

## 🛡️ Business Rules
1. **Property Isolation**: Utilities are configured per property and cannot be shared across different properties owned by the same landlord.
2. **Billing Types**: Restricts types to the `BillingType` enum (`Flat`, `Metered`, `Shared`).
