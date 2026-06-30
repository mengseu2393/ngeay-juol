# 🧠 AI START HERE

Welcome, AI Coding Assistant! This Obsidian vault is the **second brain** for the **RentWise** codebase. It contains structured, interconnected documentation to help you quickly understand the project and execute tasks without full-codebase scanning.

## 🧭 Vault Navigation

- **[[00 Dashboard]]**: The main hub for the project, showing high-level status, quick links, and context.
- **[[01 Architecture]]**: Overview of the system layout, panels, and routing maps.
- **[[02 Coding Standards]]**: Formatting, naming conventions, and coding style guidelines.
- **[[03 Tech Stack]]**: Detailed breakdown of frameworks, versions, and dependencies.

---

## 🗂️ Knowledge Domains

### 🎨 [[Frontend/Routing|Frontend & UI]]
- Components, layouts, pages, and client-side routing.
- [[Frontend/Layouts]] | [[Frontend/Components]] | [[Frontend/Pages]]

### ⚙️ Backend
- **Core Models**:
  - [[Backend/Models/User|User]]
  - [[Backend/Models/Property|Property]]
  - [[Backend/Models/Unit|Unit]]
  - [[Backend/Models/Rental|Rental]]
  - [[Backend/Models/Invoice|Invoice]]
  - [[Backend/Models/InvoiceLine|InvoiceLine]]
  - [[Backend/Models/Payment|Payment]]
  - [[Backend/Models/PropertyUtility|PropertyUtility]]
  - [[Backend/Models/UtilityUsage|UtilityUsage]]
  - [[Backend/Models/UtilityWaiver|UtilityWaiver]]
  - [[Backend/Models/LandlordProfile|LandlordProfile]]
  - [[Backend/Models/TenantProfile|TenantProfile]]
- **Scopes & Concerns**:
  - [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord Concern]]
  - [[Backend/Models/Scopes/LandlordScope|LandlordScope]]
- **Business Services**:
  - [[Backend/Services/InvoiceBuilderService|InvoiceBuilderService]]
  - [[Backend/Services/UtilityBillingService|UtilityBillingService]]
  - [[Backend/Services/SubscriptionService|SubscriptionService]]
  - [[Backend/Services/RoomAccountService|RoomAccountService]]
  - [[Backend/Services/TenancyService|TenancyService]]
  - [[Backend/Services/InvoiceExcelExport|InvoiceExcelExport]]
  - [[Backend/Services/InvoicePdfService|InvoicePdfService]]
- **Access Policies**:
  - [[Backend/Policies/LandlordOwnedPolicy|LandlordOwnedPolicy (Base)]]
  - [[Backend/Policies/UserPolicy|UserPolicy]]

### 💾 [[Database/ER Diagram|Database Schema]]
- ER Diagrams, migrations, relationships, and seeders.
- [[Database/ER Diagram|ER Diagram]]
- [[Database/Relationships|Relationships & Constraints]]
- [[Database/Migrations|Migrations Map]]
- [[Database/Seeders|Seeders Map]]

### 🔌 API Documentation
- Mobile apps and internal client endpoints.
- [[API/Auth API]] | [[API/Payment API]] | [[API/Rental API]]

### 💡 Decisions & Changelogs
- Historical architectural decisions, TODOs, and bug logs.
- [[Decisions/Why Laravel]] | [[Decisions/Why Filament]] | [[TODO]] | [[Changelog]]
