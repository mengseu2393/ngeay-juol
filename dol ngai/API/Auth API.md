# 🔌 Auth API

---

## 🎯 Status
RentWise does not currently expose public REST API endpoints for authentication. 

---

## ⚙️ Internal Auth Architecture
- **Web Auth**: Driven by **Laravel Fortify** configuration.
- **Filament Auth**: Integrates directly with session authentication (managed via `AdminPanelProvider` and `LandlordPanelProvider` middleware stacks).
- **Tenant Portal Auth**: Custom session-based login controller (`TenantPortalController@login`) checking username/password combinations.
- **Social Login**: Schema has fields for social auth keys but they are currently deactivated.
