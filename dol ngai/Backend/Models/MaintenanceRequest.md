# 🔧 MaintenanceRequest Model

- **File Path**: [MaintenanceRequest.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/MaintenanceRequest.php)
- **Table Name**: `maintenance_requests`
- **Related Feature**: [[Features/Maintenance]]

---

## 🎯 Purpose
The `MaintenanceRequest` model represents a maintenance issue reported by a tenant or landlord for a specific unit (e.g. broken AC, water leak).

---

## ⚙️ Responsibilities & Behaviors
- **Issues Tracking**: Records request title, description, priority (High, Medium, Low), and current status (Pending, In Progress, Resolved).
- **Attachment Store**: Integrates with Spatie MediaLibrary to attach photos under the `photos` collection.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/User|tenant()]]** (BelongsTo): The tenant user who submitted the request.
- **[[Backend/Models/Property|property()]]** (BelongsTo): Parent property.
- **[[Backend/Models/Unit|unit()]]** (BelongsTo): Unit affected.
- **[[Backend/Models/Rental|rental()]]** (BelongsTo): Associated active lease.
- **[[Backend/Models/MaintenanceMessage|messages()]]** (HasMany): Chat communication log between landlord and tenant concerning the request.
