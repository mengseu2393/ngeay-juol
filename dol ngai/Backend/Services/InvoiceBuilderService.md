# ⚙️ InvoiceBuilderService

- **File Path**: [InvoiceBuilderService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/InvoiceBuilderService.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
The single, centralized service for generating and persisting invoices. It replaces separate, copy-pasted invoice logic scattered across the system, ensuring data integrity for billing runs.

---

## ⚙️ Responsibilities & Behaviors
- **Invoice Number Generation**: Creates a structured unique reference (`INV-{landlordId}-{YYYYMM}-{seq}`). Runs a loop to ensure no duplication/race conditions in database keys.
- **Invoicing Transaction**: Employs a database transaction to build the parent [[Backend/Models/Invoice|Invoice]] and append the various [[Backend/Models/InvoiceLine|InvoiceLines]]:
  - Base rent line (pulled from lease context).
  - Utility lines (priced and waiver-resolved using the [[Backend/Services/UtilityBillingService|UtilityBillingService]]).
  - Ad-hoc charges (from user-supplied descriptions and values).
- **Recalculation Integration**: Resolves initial payment status based on ledger calculations.

---

## 🛠️ Public Methods

### `generateNumber(int $landlordId, Carbon $period): string`
- **Logic**: Formats the prefix and appends a zero-padded sequential integer based on the count of existing invoices. Performs check loop to avoid duplication.

### `create(array $data): Invoice`
- **Parameters**: An options array including `rental_id`, `period_start`, `period_end`, `include_rent` boolean, `usages` list, `adhoc` list, etc.
- **Returns**: Persisted and refreshed `Invoice` model.

---

## 🛡️ Business Rules & Algorithms
1. **FIFO Invoicing**: Rent is automatically set from the lease contract's rate.
2. **Transaction Isolation**: Any error during lines creation (e.g. utility usage fetching) aborts the entire transaction, preventing empty/corrupted invoices.
