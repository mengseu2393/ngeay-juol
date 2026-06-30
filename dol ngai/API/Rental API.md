# 🔌 Rental API

---

## 🎯 Status
RentWise does not currently expose public REST API endpoints for lease or rental administration.

---

## ⚙️ Internal Rental Operations
- **Lease Creation**: Handled directly in the landlord dashboard (`RentalResource`).
- **Overlap Validation**: Calls `TenancyService` methods synchronously to enforce date restrictions at the application layer.
- **Login Generation**: Triggers `RoomAccountService` to create tenant logins when a rental is activated.
