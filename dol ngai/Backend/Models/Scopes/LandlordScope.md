# 🛡️ LandlordScope Global Scope

- **File Path**: [LandlordScope.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Scopes/LandlordScope.php)

---

## 🎯 Purpose
Implements **fail-closed landlord data isolation** at the database level. Ensures that queries automatically append a `landlord_id` constraint when accessed by landlords or managers.

---

## ⚙️ Responsibilities & Behaviors
- **CLI/Queue Bypass**: Does not apply scoping if there is no authenticated session (`! Auth::user()`).
- **Staff Bypass**: Platform administrators and support staff are exempted from scoping (`User::isPlatformStaff()`), allowing them global visibility.
- **Landlord Constraints**: Automatically appends `where('landlord_id', $landlordId)` using the effective landlord ID of the authenticated user.
- **Tenant Exemption**: Tenants are not scoped here; their isolation is managed via route policies and explicit `tenant_id` database queries on client-facing portal pages.
