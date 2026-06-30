# 🛏️ Feature Spec: Tenancy & Leases

- **Context Link**: [[Features/Rental]]
- **Associated Models**: [[Backend/Models/Rental]], [[Backend/Models/Unit]], [[Backend/Models/User]]
- **Associated Services**: [[Backend/Services/TenancyService]], [[Backend/Services/RoomAccountService]]

---

## 🎯 Feature Purpose
Tracks active lease periods, occupant profiles, security deposits, and automatically manages the room's occupancy status and login accounts.

---

## ⚙️ Key Operations & Flows
- **Lease Creation**: Landlord selects a vacant unit, fills in occupant info, start date, monthly rent, and logs deposit values.
- **Login Account Minting**: On saving, a tenant login account is minted (`RoomAccountService::createForRental`) and linked to the lease.
- **Ending Leases**: Vacating a tenant shifts the rental status to `Vacated` and triggers options to restore the unit status to `Available`.

---

## 🛡️ Business Rules
- **Date Overlap Prevention**: No two active tenancies can overlap dates on a single unit (`TenancyService::hasOverlap`).
- **Single Active tenancy**: A unit may only hold a maximum of one `Active` lease at any time (`TenancyService::hasActiveTenancy`).
- **Auto Status Lock**: Unit status flips to `Occupied` upon lease activation.
- **Account State Sync**: Ending a rental deactivates the tenant user account (exempting permanent room login accounts).
