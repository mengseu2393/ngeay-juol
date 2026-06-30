# 🐛 Known Bugs & Resolutions

Tracked codebase bugs, symptoms, and resolution details:

---

## 🛠️ Resolved Bugs

### 1. Invoice markPaid Balance Drift
- **Symptom**: Editing/deleting payment records left the invoice's `amount_paid` out-of-sync.
- **Resolution**: Removed the ability to write to `amount_paid` directly. Payments now recompute totals dynamically on saving (`Invoice@recalculateFromLedger`), keeping balances locked with the ledger.

### 2. Room Count Drift
- **Symptom**: Deleting units left out-of-sync room totals on the parent property profile.
- **Resolution**: Replaced the static database column with computed attributes (`totalRooms` and `totalFloors` on the `Property` model) that count relationships on the fly.
