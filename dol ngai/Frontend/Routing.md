# 🔀 Frontend Routing Map

The frontend of RentWise is built on top of Livewire and custom Blade templates. It governs client-facing routes for tenants.

---

## 🗺️ Portal Route Registry

All tenant routes are prefix-scoped and managed in [web.php](file:///home/tms/Desktop/dol-ngai/rentwise/routes/web.php):

| URL Path | Route Name | Controller Action | Purpose |
| :--- | :--- | :--- | :--- |
| `/portal/login` | `portal.login` | `TenantPortalController::showLogin` | Renders tenant login form. |
| `/portal/login` | `portal.login.attempt` | `TenantPortalController::login` | POST handler (throttled to 6 attempts/min). |
| `/portal/` | `portal.dashboard` | `TenantPortalController::dashboard` | Portal dashboard (lists active/past bills). |
| `/portal/invoices/{invoice}` | `portal.invoice` | `TenantPortalController::invoice` | Detailed invoice review and printing. |
| `/portal/logout` | `portal.logout` | `TenantPortalController::logout` | Ends tenant session. |

---

## 🔒 Routing Guards
- Routes inside the `/portal` dashboard are guarded by `TenantPortalController` checks to redirect unauthenticated guest users back to `/portal/login`.
- Access is restricted to users with role `tenant`.
