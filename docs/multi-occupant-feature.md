# Multi-Occupant Per Rental Agreement

> Allow multiple tenants (co-tenants & dependents) to be stored under a single rental agreement while keeping one clear **primary tenant** as the billing and login owner.

## Overview

Each rental agreement supports **one primary tenant** plus unlimited **co-tenants** and **dependents**. The primary tenant is the person responsible for billing, login, and the lease. Additional occupants are people sharing the room (e.g. spouse, roommates, children).

### Occupant Roles

| Role | Description | Can Login? | Receives Invoices? |
|------|-------------|------------|---------------------|
| **Primary** | Main tenant — the billing/login owner | ✅ Yes | ✅ Yes |
| **Co-Tenant** | Additional adult sharing the room (e.g. roommate, spouse) | ❌ No | ❌ No |
| **Dependent** | Family member / minor (e.g. child) | ❌ No | ❌ No |

## Data Model

### Tables

```
┌─────────────────────┐        ┌──────────────────────────┐
│       rentals        │        │    rental_occupants       │
├─────────────────────┤        ├──────────────────────────┤
│ id                  │◄──┐    │ id                       │
│ tenant_id (FK→users)│   │    │ rental_id (FK→rentals)   │──┐
│ unit_id (FK→units)  │   │    │ user_id (FK→users, null) │  │
│ occupant_name       │   │    │ role (primary/co_tenant/  │  │
│ occupant_phone      │   │    │        dependent)         │  │
│ monthly_rent        │   │    │ occupant_name             │  │
│ status              │   │    │ occupant_phone            │  │
│ start_date          │   │    │ occupant_id_card          │  │
│ ...                 │   │    │ occupant_gender           │  │
└─────────────────────┘   │    │ occupant_dob              │  │
                          │    │ occupant_nationality      │  │
                          │    │ occupant_workplace        │  │
                          │    │ occupant_address          │  │
                          │    │ emergency_contact_*       │  │
                          │    │ guarantor_*               │  │
                          └────│ ...                       │  │
                               └──────────────────────────┘  │
                                                             │
                               ┌──────────────────────────┐  │
                               │         units            │  │
                               ├──────────────────────────┤  │
                               │ max_occupants (default 1)│◄─┘
                               └──────────────────────────┘
```

### Key Relationships (Rental Model)

| Method | Return Type | Description |
|--------|-------------|-------------|
| `occupants()` | `HasMany` | All occupants (primary + co-tenants + dependents) |
| `primaryOccupant()` | `HasOne` | The primary (responsible) occupant only |
| `occupant_names` | Accessor | Comma-separated list of all occupant names |

### Key Files

| File | Purpose |
|------|---------|
| `app/Models/Rental.php` | Rental model with occupant relationships |
| `app/Models/RentalOccupant.php` | Occupant model with per-person details |
| `app/Enums/OccupantRole.php` | Enum: `Primary`, `CoTenant`, `Dependent` |
| `app/Filament/Resources/UnitResource/RelationManagers/RentalsRelationManager.php` | UI form with occupants repeater |
| `database/migrations/2026_07_22_100001_create_rental_occupants_table.php` | Occupants table schema |

## UI / UX

### Where: Unit Edit Page → Tenants Tab

The occupant management lives inside the **rental create/edit modal** on the unit edit page (`/landlord/units/{id}/edit` → Tenants tab).

### Form Layout

```
┌─────────────────────────────────────────────────────┐
│  § Tenancy                                          │
│    Full name, Phone, Gender, DOB, Nationality...    │ ← This IS the primary tenant
│    ID card photos, Status, Rent, Start date...      │
│                                                     │
│  § Agreement & Emergency / Guarantor Details  [▸]   │ ← Collapsed
│                                                     │
│  § Additional Occupants                       [▸]   │ ← Collapsed
│    ┌───────────────────────────────────────────┐    │
│    │ ★ Primary Tenant: Sokha Meng (read-only) │    │
│    │                                           │    │
│    │ [+ Add occupant]                          │    │
│    │                                           │    │
│    │ This room allows up to 4 occupants        │    │
│    └───────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

### Rules That Prevent Confusion

| Rule | Implementation |
|------|----------------|
| Only 1 primary per rental | The Tenancy section IS the primary. No `primary` option in the repeater's role dropdown |
| Primary clearly identified | Green badge: `★ Primary Tenant: {name}` at top of occupants section |
| Room capacity enforced | Repeater `maxItems` = `units.max_occupants - 1` (primary occupies 1 slot) |
| Primary stays in sync | On edit, the `rental_occupants` primary record auto-syncs with the rental's occupant fields |
| Occupant count visible | Room list table shows `X/Y occupants` under the tenant name |

## How It Works

### Creating a New Tenant (with occupants)

1. Landlord clicks **"Add tenant"** on the Tenants tab
2. Fills out the **Tenancy section** (this becomes the primary tenant)
3. Optionally expands **"Additional Occupants"** and clicks **"+ Add occupant"**
4. Selects role (Co-Tenant or Dependent) and fills in details
5. On save:
   - Rental record created with `tenant_id` (login account auto-created)
   - Primary `rental_occupants` record created from the tenancy fields
   - Each additional occupant saved as a separate `rental_occupants` record

### Editing a Tenant

1. Landlord clicks edit on an existing tenant
2. Existing co-tenants/dependents auto-load into the repeater
3. Can add new occupants, edit existing ones, or remove them
4. On save:
   - Rental record updated
   - Primary occupant record synced with rental fields
   - Additional occupants: new ones created, existing updated, removed ones deleted

### Viewing a Tenant

- View mode loads all occupants into the repeater (read-only)

## Occupant Count on Room List

The room list table already shows occupant count:

```
Tenant: Sokha Meng, Wife
        2/4 occupants
```

This pulls from `Rental::occupants()->count()` vs `Unit::max_occupants`.

## Future Enhancements

- [ ] Per-occupant ID card photo upload (model supports it via Spatie Media Library `id_cards` collection)
- [ ] Per-occupant emergency contact & guarantor in the repeater (DB columns exist, just not in the UI yet)
- [ ] Occupant-level portal login (allow co-tenants to have their own login)
- [ ] Move-in/move-out tracking per occupant (not just per rental)
