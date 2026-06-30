# ⚙️ InvoiceExcelExport

- **File Path**: [InvoiceExcelExport.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/InvoiceExcelExport.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
Streams detailed Excel spreadsheet worksheets (`.xlsx`) of tenant invoices using a memory-efficient write stream.

---

## ⚙️ Responsibilities & Behaviors
- **Low-Memory Writing**: Employs `OpenSpout\Writer\XLSX\Writer` to stream records directly to the PHP output buffer without storing full files in memory.
- **Invoice Formatting**: Maps the invoice title block, line items (with description type, quantity, rate, amount), payment history, and subtotal ledgers to formatted excel rows.
- **Waiver Formatting**: Automatically flags and displays waived lines.

---

## 🛠️ Public Methods

### `download(Invoice $invoice): StreamedResponse`
- **Logic**: Eager-loads relations and returns a downloadable streamed response.
