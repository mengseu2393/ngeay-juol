# TODO

- Add focused tests for charge-state visibility across invoice PDF, Excel export, slip modal, and invoice history.
- Add regression coverage for `Free`, `Waived`, `Adjusted`, `Not applicable`, and `Skipped this cycle` so the hidden states never leak back into tenant-facing output.
- Add an end-to-end billing test that proves concession value is preserved on invoice lines while the displayed amount stays zero for free/waived charges.
- Re-run the billing and reporting feature suite on a machine with SQLite PDO enabled to validate the new state helpers end to end.
- Review whether the legacy utility waiver path can be removed now that charge rules are the primary workflow.
- Add a dedicated room/rental charge-rule overview so inherited property rules and overrides are visible outside billing review.
