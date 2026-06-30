# 🔑 Feature Spec: Authentication & Role Access

- **Context Link**: [[Features/Authentication]]
- **Associated Models**: [[Backend/Models/User]], [[Backend/Models/LandlordProfile]], [[Backend/Models/TenantProfile]]

---

## 🎯 Feature Purpose
Authenticates platform staff, landlords, managers, and tenants, while restricting database operations to role-based safety constraints (RBAC).

---

## 👥 Roles & Access Levels

1. **`super_admin`**: Platform owner. Accesses the `/admin` panel to control pricing/subscriptions and can access any landlord context via `/landlord` panels.
2. **`support`**: Platform agent. Accesses `/admin` to troubleshoot, view settings, and read logs across landlords.
3. **`landlord`**: Property owner. Accesses `/landlord` back-office to control properties, pricing groups, and invoices.
4. **`landlord_manager`**: Manager employee. Accesses `/landlord` back-office to run daily operations (e.g. record utility readings, log rent payments) but lacks deletion/rate-change permissions.
5. **`tenant`**: Room occupant. Logged out of back-office dashboards. Accesses `/portal` (Livewire) using simple credentials to pay invoices.

---

## 🛡️ Business Rules
- **Suspended Accounts**: Any user status other than `Active` blocks login immediately across all panels and portals.
- **Self-Deletion Guard**: Users are blocked from deleting their own logged-in profiles.
- **Tenant Login Creation**: Landlords/managers can only mint user accounts for tenants if their subscription tier has the `can_create_tenants` flag enabled.
