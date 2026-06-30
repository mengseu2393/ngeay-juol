# 🧾 InvoiceLine Model

- **File Path**: [InvoiceLine.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/InvoiceLine.php)
- **Table Name**: `invoice_lines`
- **Related Feature**: [[Features/Payment]]

---

## 🎯 Purpose
The `InvoiceLine` model represents an individual itemized charge on a [[Backend/Models/Invoice|Invoice]] (e.g. basic monthly room rent, electricity usage, water usage, or ad-hoc maintenance fees).

---

## ⚙️ Responsibilities & Behaviors
- **Itemized Scoping**: Holds the details of what is being billed, including the quantity (e.g., kW of electric used), unit price, total amount, and waiver status.
- **Parent Invoice Sync**:
  - `saved` / `deleted`: Hooks automatically trigger `$line->invoice->recalculateAmountDue()` to ensure the parent invoice total stays in sync.
- **Waiver Flags**: Supports an `is_waived` boolean flag, setting the line value but excluding it from calculations if waived.

---

## 🔗 Relationships
- **[[Backend/Models/Invoice|invoice()]]** (BelongsTo): The parent invoice.
- **[[Backend/Models/UtilityUsage|utilityUsage()]]** (BelongsTo): Optional association. Links metered lines (like electricity or water) directly to the specific meter reading record.

---

## 🛡️ Business Rules
1. **Quantity Precision**: Custom casting of `quantity` to `decimal:3` (handles fractional usages like water cubic meters) and `unit_price` to `decimal:4`.
2. **Auto Recalculation**: Any edit, deletion, or creation of a line item recalculates the parent invoice's `amount_due` in a fail-safe event handler.
