# 💡 Decision: Why Filament v3?

---

## 🎯 Context
The application serves two distinct business groups: platform administrators/support agents, and landlords/managers. Designing custom dashboards for both would require massive frontend duplication.

---

## ⚖️ Evaluation & Choices
- **Filament v3** was chosen due to its **multi-panel routing architecture**:
  - **Panel Isolation**: Allows building separate panels (`/admin` and `/landlord`) sharing the same codebase but with fully distinct resource maps and middlewares.
  - **Zero-Boilerplate CRUD**: Automatic form/table layout rendering.
  - **Filament Shield**: Standardizes RBAC policies automatically for Shield resources, resolving permissions using simple names.
  - **Property Scoping Support**: The sidebar can dynamically adjust headers and list groups using page headers or Livewire widgets (e.g. `PropertySwitcher`), allowing the landlord panel to scope queries seamlessly.
