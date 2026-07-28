# Instructions for 58mm Thermal Printer Invoice Printing

## Goal
Add a new button for printing invoices specifically formatted for 58mm thermal printers. This button should be added to the "Simple Mode Invoice" interface alongside the existing "Add" and other print buttons.

## Requirements

1.  **UI Element**:
    *   Add a new button labeled "Print 58mm" (or similar) in the simple mode invoice section.
    *   Place it next to the existing "Add" and "Print" buttons.

2.  **Print Formatting (Crucial for 58mm)**:
    *   **Paper Width**: The print layout must be strictly constrained to fit within a 58mm width (typically around `48mm` printable area or ~`204px` to `220px` width depending on density).
    *   **Font Size & Readability**: Ensure fonts are legible and clear. They must not be too small, but also not so large that they cause unwanted text wrapping or horizontal scrolling. 
    *   **Margins/Padding**: Remove excessive margins and padding in the print stylesheet (`@media print`) to maximize the usable width.
    *   **Content Layout**: Reformat the invoice content to be completely vertical. Avoid horizontal multi-column layouts (like tables with many columns) that won't fit a narrow receipt. Use a simple, linear layout for items, quantities, and prices.
    *   **Text Wrapping**: Ensure long item names or text wrap correctly without bleeding off the edge of the paper.

3.  **Functionality**:
    *   Clicking the "Print 58mm" button should trigger the browser's print dialog, applying the specific 58mm print stylesheets.
    *   Ensure it works reliably with standard small POS thermal receipt printers.

## Implementation Notes (for the AI/Developer implementing this)
*   You will likely need to create a specific CSS class or `@media print` block that triggers only when this specific button is clicked. A common approach is to dynamically add a class like `.print-58mm` to the `<body>` or the invoice container right before calling `window.print()`, and remove it after.
*   Test the layout carefully in the browser's print preview by setting the paper size/margins to simulate a 58mm narrow width to verify the fonts and layout fit perfectly.
