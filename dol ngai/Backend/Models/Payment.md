# 💵 Payment Model

- **File Path**: [Payment.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Payment.php)
- **Table Name**: `payments`
- **Related Feature**: [[Features/Payment]]

---

## 🎯 Purpose
The `Payment` model records financial transactions paid by tenants to cover their generated [[Backend/Models/Invoice|Invoices]].

---

## ⚙️ Responsibilities & Behaviors
- **Transaction Metadata**: Holds payment amounts, payment methods (Cash, Card, Bank Transfer, Mobile Payment), bank references, receipt numbers, and transaction timestamps.
- **Ledger Recalculation (Single Choke Point)**:
  - `saved` / `deleted`: Event hooks execute `$payment->invoice->recalculateFromLedger()` to sum payments and update status on the parent invoice. This guarantees the invoice ledger never drifts.

---

## 🔗 Relationships
- **[[Backend/Models/Invoice|invoice()]]** (BelongsTo): The invoice being paid.
- **recordedBy()** (BelongsTo): The [[Backend/Models/User|User]] (staff/landlord) who recorded the payment in the system.

---

## 🛡️ Business Rules
1. **Immutable Amount Writing**: Edits/deletions of payment amounts trigger parent invoice recalculations to prevent orphaned balances.
2. **Payment Method Enumeration**: Restricts methods to values defined in the `App\Enums\PaymentMethod` enum.
