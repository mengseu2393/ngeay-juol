# 🧠 Active Session Memory

This file is updated at the end of each session to hand over context to the next AI coding assistant.

---

## 📅 Session Log: 2026-06-29

### 🎯 Objective Achieved
- **Tab Label Correction**: Corrected the label on `/admin/users` page inside [UserResource.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Filament/Resources/UserResource.php) from Tenants to Tenant.
- **Knowledge Base Setup**: Built the initial structure of the Obsidian vault (`dol ngai`) detailing Tech Stack, Database Schema, Models, Services, Policies, and Features.
- **Monthly Invoicing Refactoring**:
  - Streamlined the bulk [MonthlyBilling.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Filament/Pages/MonthlyBilling.php) page by removing manual billing period and due date selectors.
  - Configured reactive loading that automatically pulls occupied rooms and active metered/shared utilities when a property is chosen.
  - Implemented dynamic pro-rated rent calculations based on actual occupancy days (from rental start date or last invoice up to the billing issue date).
  - Added checkboxes to allow selecting/deselecting individual occupied rooms for the billing run, automatically hiding/showing their input fields.
- **Unit Occupancy Lock-Step Bug Fix**:
  - Resolved a critical bug in [RoomAccountService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/RoomAccountService.php#L113) where `saveQuietly()` bypassed Eloquent saved events during tenant creation, leaving room status stuck on "Available". Changed to `save()`.
  - Synced database status for room 1001 to mark it as **Occupied**.

### 🧭 Next Action Items
- Continue documenting custom Filament pages (Property Settings, Consumption History, Monthly Billing) under the `Frontend/` folder.
- Document polymorphic chat room controllers and Spatie permission tables.

