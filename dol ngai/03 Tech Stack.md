# 🛠️ Tech Stack

This document details the core framework versions, packages, and dependencies that power the RentWise application.

---

## 🗃️ Core Stack

- **PHP**: `^8.2`
- **Laravel Framework**: `^11.0` (with native features like SQLite/MySQL schema drivers, Laravel Fortify, Vite assets)
- **Filament Panels**: `^3.0` (provides `/admin` and `/landlord` dashboards)
- **Livewire**: `^3.0` (interactive UI components)
- **Alpine.js**: Integrated with Livewire/Filament for client-side state
- **Database**:
  - Production/Staging: MySQL 8.x
  - Local testing: SQLite (in-memory or file-based)

---

## 📦 Key Composer Packages

- **`spatie/laravel-permission`**: Role-based access control (RBAC).
- **`bezhan-salleh/filament-shield`**: Automatic generation of policies and permissions mapped to Filament resources.
- **`spatie/laravel-activitylog`**: Tracks model events (creation, edits) automatically for audit logs.
- **`spatie/laravel-medialibrary`**: Handles files, ID cards, uploads, and image conversions.
- **`dompdf/dompdf`**: Generates invoice PDFs (with customized font support for Noto Sans Khmer).
- **`box/spout`** (or `openspout/openspout`): Processes fast, low-memory Excel exports for invoicing.
- **`laravel/fortify`**: Auth logic backend.
