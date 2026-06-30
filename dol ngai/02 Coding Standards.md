# 📜 Coding Standards

This document establishes the patterns, guidelines, and standards for developers working on the RentWise project.

---

## 🗂️ Project Structure

- **Models**: Business entities live in `app/Models/` and represent highly normalized database records.
- **Enums**: All entity states, statuses, and types are mapped to PHP enums under `app/Enums/`.
- **Services**: Complex business logic (billing, PDF creation, tenancies) is decoupled from controllers/resources and placed in `app/Services/`.
- **Policies**: Every database model has an associated Spatie policy in `app/Policies/` defining role-based query scoping and edit access.

---

## 🛡️ Security Guidelines

### 1. Mass Assignment Protection
- All models must protect sensitive columns (like `status`, `created_by_id`, `manages_landlord_id`) from mass assignment.
- Keep `$fillable` limited to user-editable fields.
- Update guarded fields explicitly using `$model->forceFill($data)->save();`.

### 2. Password Hashing
- Always use the `'password' => 'hashed'` cast on the `User` model to automatically handle password encryption.
- Do not hash passwords manually in services or controller code.

### 3. Landlord & Tenant Isolation
- Data must remain isolated. Every query on property-owned resources in the landlord panel must apply the `LandlordScope`.
- Livewire components must fetch models using scoped bindings (e.g., matching authenticated user's landlord/tenant associations).

---

## 🌐 Localization & Translation

- Every string displayed to users must be wrapped in `__('Your Translation String')`.
- All translation keys are stored in `lang/km.json` for Khmer and default back to English.
- Avoid using hardcoded text inside tables, forms, templates, or emails.
