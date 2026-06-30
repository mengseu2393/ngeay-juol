# 🔌 BelongsToLandlord Concern

- **File Path**: [BelongsToLandlord.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Concerns/BelongsToLandlord.php)

---

## 🎯 Purpose
A shared Eloquent trait applied to any model that contains a denormalized `landlord_id` column. It automates global query scoping and default parameter injection.

---

## ⚙️ Responsibilities & Behaviors
- **Scoping**: Registers the [[Backend/Models/Scopes/LandlordScope|LandlordScope]] global scope automatically during boot.
- **Auto-Filling**:
  - Automatically hooks into the model's `creating` event.
  - If `landlord_id` is empty, it attempts to resolve the currently logged-in user's effective landlord ID (`User::effectiveLandlordId()`).
  - Fallback logic: Runs `resolveLandlordId()` on the model if it exists (useful for nested resources or seeders).

---

## 🔗 Relationships
- **landlord()** (BelongsTo): Maps the model's `landlord_id` to the [[Backend/Models/User|User]] model.
