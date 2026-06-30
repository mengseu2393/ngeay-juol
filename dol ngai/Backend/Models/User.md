# 👤 User Model

- **File Path**: [User.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/User.php)
- **Table Name**: `users`
- **Related Feature**: [[Features/Authentication]]

---

## 🎯 Purpose
The `User` model represents all authenticatable accounts in the RentWise system, including platform administrators, support staff, landlords, landlord managers, and tenants.

---

## ⚙️ Responsibilities & Behaviors
- **Authentication**: Extends Laravel's `Authenticatable` system and works with Fortify.
- **RBAC**: Uses Spatie's `HasRoles` trait to enforce roles (`super_admin`, `support`, `landlord`, `landlord_manager`, `tenant`).
- **Media Uploads**: Interacts with Spatie MediaLibrary to store avatars and ID card images.
- **Audit Trails**: Automatically logs updates to profile fields via Spatie Activitylog.

---

## 🔗 Relationships
- **[[Backend/Models/LandlordProfile|landlordProfile()]]** (HasOne): Landlord-specific details like company and bank transfer info.
- **[[Backend/Models/TenantProfile|tenantProfile()]]** (HasOne): Tenant-specific details.
- **createdBy()** (BelongsTo): Refers to the staff or landlord user who created this account.
- **managesLandlord()** (BelongsTo): For role `landlord_manager`, links to the main `landlord` account.
- **[[Backend/Models/Property|properties()]]** (HasMany): Scoped to properties owned by this user (if role is landlord).
- **[[Backend/Models/Rental|rentalsAsLandlord()]]** / **[[Backend/Models/Rental|rentalsAsTenant()]]** (HasMany): Leases associated with this user.
- **[[Backend/Models/Invoice|invoicesAsLandlord()]]** / **[[Backend/Models/Invoice|invoicesAsTenant()]]** (HasMany): Scoped billings.

---

## 🛠️ Public Methods

### `canAccessPanel(Panel $panel): bool`
- **Logic**: Restricts entry to panels based on active status and roles.
  - `/admin` ➔ Roles: `super_admin`, `support`
  - `/landlord` ➔ Roles: `super_admin`, `landlord`, `landlord_manager`
  - Tenants cannot access Filament panels; they are redirected to the custom portal.

### `effectiveLandlordId(): ?int`
- **Logic**: Resolves the target landlord's user ID.
  - Returns its own ID if the user is a `landlord`.
  - Returns `manages_landlord_id` if the user is a `landlord_manager`.
  - Returns `null` for tenants, admins, or support.

### `canCreateTenants(): bool`
- **Logic**: Returns `true` if the user has platform staff rights, or if they are a landlord/manager whose [[Backend/Models/LandlordProfile|LandlordProfile]] has the `can_create_tenants` flag enabled.

---

## 🛡️ Business Rules
1. **Guarded Attributes**: Social IDs, creation author, managers, and status fields are guarded against mass assignment and can only be set explicitly.
2. **Password Casting**: Hashed automatically using the `'password' => 'hashed'` cast.
3. **Status Check**: Suspended accounts (status other than `Active`) are blocked from logging in.
