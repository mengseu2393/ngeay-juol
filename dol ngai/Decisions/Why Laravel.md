# 💡 Decision: Why Laravel?

---

## 🎯 Context
The original system was split between multiple ad-hoc scripts with duplicated invoice builders and weak access controls. The rebuild needed a robust, batteries-included framework that could enforce security bounds and provide stable abstractions.

---

## ⚖️ Evaluation & Choices
- **Laravel** was selected because of its built-in, mature ecosystems:
  - **Eloquent ORM**: Eager loading, global query scopes (used to enforce [[Backend/Models/Scopes/LandlordScope|LandlordScope]]), and simple relationships.
  - **Database Migration Engine**: Controlled DDL scripts.
  - **Artisan Command Line**: Automated commands, cron scheduling, and seeders.
  - **Built-in Localization**: Clean translations helper `__('string')` with JSON fallbacks.
  - **Security Abstractions**: Robust hashing cast, middleware pipelines, and CSRF protection.
