# 💵 Invoice Model

- **File Path**: [Invoice.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/Invoice.php)
- **Table Name**: `invoices`
- **Related Feature**: [[Features/Payment]]

---

## 🎯 Purpose
The `Invoice` model represents a billing statement issued to a tenant for a specific period. It includes basic rent and itemized metered utilities (water, electricity, garbage, etc.).

---

## ⚙️ Responsibilities & Behaviors
- **Ledger Single Source of Truth**:
  - `amount_paid` is protected from direct writes to prevent data drift. It is derived entirely by summing associated payments.
  - Recomputes `payment_status` automatically when payments are recorded.
- **Dynamic Calculation Methods**:
  - Recomputes total `amount_due` by summing line items.
  - Exposes a computed `balance` attribute showing the remaining unpaid portion of the bill.
- **Landlord Scoping**: Utilizes the [[Backend/Models/Concerns/BelongsToLandlord|BelongsToLandlord]] trait to apply global landlord isolation.

---

## 🔗 Relationships
- **[[Backend/Models/Rental|rental()]]** (BelongsTo): The associated tenancy lease.
- **[[Backend/Models/Property|property()]]** (BelongsTo): The parent property.
- **[[Backend/Models/User|tenant()]]** (BelongsTo): The occupant who owes the bill.
- **[[Backend/Models/InvoiceLine|lines()]]** (HasMany): Itemized billing lines (Rent, Water, Electric, etc.).
- **[[Backend/Models/Payment|payments()]]** (HasMany): Payments recorded against this invoice.

---

## 🛠️ Public Methods & Algorithms

### `recordPayment(array $attributes): Payment`
- **Logic**: Executes a database transaction to insert a new [[Backend/Models/Payment|Payment]] record linked to this invoice.

### `recalculateFromLedger(): void`
- **Logic**: Queries the sum of associated payments, updates `amount_paid`, recalculates `payment_status`, and saves the record quietly.

### `recalculateAmountDue(): void`
- **Logic**: Queries the sum of line items, updates `amount_due`, recalculates `payment_status`, and saves quietly.

### `resolvePaymentStatus(): InvoiceStatus`
- **Logic**: Resolves invoice state:
  - If paid sum matches or exceeds amount due ➔ `Paid`.
  - If paid sum is zero and past due date ➔ `Overdue`.
  - If paid sum is zero and future due date ➔ `Pending` (or `Draft`).
  - Otherwise ➔ `Partial`.

### `balance() (Attribute)`
- **Logic**: Returns computed remainder: `amount_due - amount_paid`.

---

## 🛡️ Business Rules
1. **Cancellation Lock**: If an invoice status is manually set to `Cancelled`, the status becomes sticky and cannot be overridden by ledger recalculations.
2. **Denormalization Sync**: Automatically resolves and saves the parent `property_id` from the rental relationship during saving to speed up queries.
