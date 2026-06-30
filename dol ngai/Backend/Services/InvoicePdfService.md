# ⚙️ InvoicePdfService

- **File Path**: [InvoicePdfService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/InvoicePdfService.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
Renders print-ready PDF invoice documents in standard A4/A5 paper sizes or narrow thermal receipt options.

---

## ⚙️ Responsibilities & Behaviors
- **PDF Compilation**: Passes invoice models and layout options to the `invoices.pdf` Blade template, rendering HTML to pdf via the Barryvdh DomPDF wrapper.
- **Paper Geometry Scoping**: Configures print page dimension rules using `App\Support\InvoicePaper`.
- **Filename Sanitization**: Sanitizes file names to yield download paths (e.g. `INV_1_2026_001.pdf`).

---

## 🛠️ Public Methods

### `make(Invoice $invoice, string $size): BasePDF`
- **Logic**: Loads relations, parses thermal layout boolean flags, and configures portrait orientation.

### `filename(Invoice $invoice, string $ext): string` (Static)
- **Logic**: Replaces non-alphanumeric characters with underscores.
