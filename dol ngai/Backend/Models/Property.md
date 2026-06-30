# 🏢 Property Model

- **File Path**: [Property.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Property.php)
- **Table Name**: `properties`
- **Related Feature**: [[Features/Property]]

---

## 🎯 Purpose
The `Property` model represents a real estate asset (e.g., apartment, house, condo) owned by a landlord. It acts as the primary grouping context for rooms/units and utility configurations.

---

## ⚙️ Responsibilities & Behaviors
- **Tenant Context Grouping**: Groups units, rentals, invoices, and maintenance requests.
- **Dynamic Metrics**:
  - `totalRooms`: Computes the room count dynamically (`units_count` or via `units()->count()`).
  - `totalFloors`: Calculates the distinct floor count.
- **Media Uploads**: Stores property photos in the `photos` collection.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **landlord()** (BelongsTo): Links to the owner [[Backend/Models/User|User]].
- **[[Backend/Models/Unit|units()]]** (HasMany): Rooms/units inside this property.
- **[[Backend/Models/Rental|rentals()]]** (HasMany): Leases associated with units in this property.
- **[[Backend/Models/Invoice|invoices()]]** (HasMany): Generated tenant invoices.
- **[[Backend/Models/PropertyUtility|propertyUtilities()]]** (HasMany): Utility rates (water, electricity, etc.) for this property.
- **[[Backend/Models/UtilityWaiver|utilityWaivers()]]** (HasMany): Active utility waivers.
- **[[Backend/Models/PropertySetting|settings()]]** (HasOne): General property defaults (lease duration, bank info, numbering format).
- **[[Backend/Models/MaintenanceRequest|maintenanceRequests()]]** (HasMany): Tracked issues.

---

## 🛠️ Public Methods & Attributes

### `totalRooms() (Attribute)`
- **Returns**: Integer room count. Prevents old out-of-sync database column drift bugs.

### `totalFloors() (Attribute)`
- **Returns**: Distinct number of floors by grouping `units.floor_number`.

---

## 🛡️ Business Rules
1. **Validation**: Must belong to a valid Landlord.
2. **Global Scoping**: Automatically applies [[Backend/Models/Scopes/LandlordScope|LandlordScope]] on all select queries to ensure data isolation.
