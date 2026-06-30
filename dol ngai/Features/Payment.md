# 💵 Feature Spec: Invoicing & Payments

- **Context Link**: [[Features/Payment]]
- **Associated Models**: [[Backend/Models/Invoice]], [[Backend/Models/InvoiceLine]], [[Backend/Models/Payment]]
- **Associated Services**: [[Backend/Services/InvoiceBuilderService]], [[Backend/Services/InvoicePdfService]], [[Backend/Services/InvoiceExcelExport]]

---

## 🎯 Feature Purpose
Generates periodic landlord-to-tenant bills, handles payments against outstanding invoices, and prints/exports receipts.

---

## ⚙️ Key Operations & Flows
- **Billing Generation**: Resolves lease and utility usage data, building line items through the [[Backend/Services/InvoiceBuilderService|InvoiceBuilderService]].
- **Payment Processing**: Landlord logs payments via cash/transfer. Ledger updates automatically.
- **Exporting**: Streams XLSX spreadsheets or DomPDF documents.

---

## 🛡️ Business Rules
- **Invoice Recalculation**: Edits on line items or payments automatically recalculate `amount_due`, `amount_paid`, and `payment_status`.
- **Draft Exemption**: Draft invoices do not affect tenant balances until marked Pending/Issued.
- **Cancelled state lock**: Cancelled invoices are frozen and cannot be updated by payments.
- **Language Scoping**: Documents automatically render in English or Khmer depending on user preference, injecting localized currencies and Khmer fonts.
