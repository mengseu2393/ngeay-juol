# 🔌 Payment API

---

## 🎯 Status
RentWise does not currently expose public REST API endpoints for payment processing.

---

## ⚙️ Internal Payment Integration
- **Transaction Logs**: Payments are logged inside the landlord dashboard using standard Filament forms.
- **Tenant Portal Updates**: When a tenant uploads transaction slips, files are uploaded via Livewire and stored under Spatie Media Collections.
- **Recalculations**: All payment ledger updates trigger internal recalculation services (`Invoice@recalculateFromLedger`) to ensure amount due syncing.
