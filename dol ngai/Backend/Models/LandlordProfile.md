# 👤 LandlordProfile Model

- **File Path**: [LandlordProfile.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/LandlordProfile.php)
- **Table Name**: `landlord_profiles`
- **Related Feature**: [[Features/Authentication]]

---

## 🎯 Purpose
The `LandlordProfile` model extends the core [[Backend/Models/User|User]] model, holding properties, bank accounts, and preferences specific to users with the role of `landlord`.

---

## ⚙️ Responsibilities & Behaviors
- **Banking Metadata**: Tracks landlord company name, bank name, account name, and account number for payouts and tenant bank transfers.
- **Delegated Authority Control**: Manages the `can_create_tenants` flag, which determines if this landlord is authorized to directly mint new tenant login accounts.

---

## 🔗 Relationships
- **[[Backend/Models/User|user()]]** (BelongsTo): The parent user account.
