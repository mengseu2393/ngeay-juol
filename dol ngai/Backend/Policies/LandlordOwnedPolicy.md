# 🛡️ LandlordOwnedPolicy (Base Policy)

- **File Path**: [LandlordOwnedPolicy.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Policies/LandlordOwnedPolicy.php)
- **Namespace**: `App\Policies`

---

## 🎯 Purpose
An abstract base policy class that provides common role and ownership verification logic for all resources owned by landlords (e.g. units, rentals, invoices, properties).

---

## ⚙️ Responsibilities & Behaviors
- **Shield & Spatie Interoperability**: Resolves permission names dynamically, supporting both standard underscore names (`view_any_property`) and compound Shield separators (`view_any_property::utility`).
- **Authorization Enforcer**: Implements default `viewAny`, `view`, `create`, `update`, `delete`, `restore`, and `forceDelete` operations.
- **Ownership Verification**: Automatically checks if the resource's `landlord_id` matches the effective landlord ID of the acting user.

---

## 🛠️ Public & Protected Methods

### `allows(User $user, string $action): bool` (Protected)
- **Logic**: Performs permission checks tolerant of naming syntax variances.

### `owns(User $user, Model $record): bool` (Protected)
- **Logic**: Returns `true` if the user is platform staff (read bypass) or if the record's landlord ID matches the user's `effectiveLandlordId()`.

### `ownerId(Model $record): ?int` (Protected)
- **Logic**: Returns the `landlord_id` of the record. Can be overridden in sub-policies for indirect relationships.
