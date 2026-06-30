# 👤 TenantProfile Model

- **File Path**: [TenantProfile.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/TenantProfile.php)
- **Table Name**: `tenant_profiles`
- **Related Feature**: [[Features/Tenant]]

---

## 🎯 Purpose
The `TenantProfile` model extends the core [[Backend/Models/User|User]] model, tracking background info, emergency contacts, occupation, and financial properties specific to tenant accounts.

---

## ⚙️ Responsibilities & Behaviors
- **Tenant Verification Info**: Stores National ID numbers, guarantor names, emergency phone numbers, occupations, and reported incomes.

---

## 🔗 Relationships
- **[[Backend/Models/User|user()]]** (BelongsTo): The parent user account.
