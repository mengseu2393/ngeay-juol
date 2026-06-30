# 🛡️ UserPolicy

- **File Path**: [UserPolicy.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Policies/UserPolicy.php)
- **Namespace**: `App\Policies`

---

## 🎯 Purpose
Controls read, write, and deletion access on the core [[Backend/Models/User|User]] model.

---

## ⚙️ Responsibilities & Behaviors
- **Access Delegation**: Restricts tenant creation based on Spatie permissions and the landlord's delegated subscription capability (`User::canCreateTenants()`).
- **Super Admin Protection**: Restricts modification/deletion of `super_admin` accounts.
- **Self-deletion Protection**: Prevents users from deleting their own active profile.
- **Ownership Scope**: Permits landlords/managers to modify only users they created or who rent one of their units.

---

## 🛠️ Public Methods

### `view(User $user, User $model): bool`
- **Logic**: Returns `true` if the actor is platform staff or if they manage the target user.

### `create(User $user): bool`
- **Logic**: Enforces that the user has the `create_user` permission and that their role allows creating tenant accounts.

### `update(User $user, User $model): bool`
- **Logic**: Rejects edits on `super_admin` accounts. Permits updates only if the actor manages the user or is platform staff.

### `delete(User $user, User $model): bool`
- **Logic**: Rejects self-deletions and operations on `super_admin` accounts. Permits deletion only if the actor manages the target user.

---

## 🛡️ Business Rules & Algorithms

### `manages(User $user, User $model): bool` (Protected)
- **Logic**: Resolves whether the actor is authorized to manage the target user:
  - If the user's `created_by_id` matches the actor's ID or their `effectiveLandlordId()`.
  - Or if the user is currently renting a unit belonging to the actor's landlord account (`rentalsAsTenant` exist scoped to `landlord_id`).
