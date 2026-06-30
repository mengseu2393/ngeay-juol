# ⚙️ UtilityBillingService

- **File Path**: [UtilityBillingService.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Services/UtilityBillingService.php)
- **Namespace**: `App\Services`

---

## 🎯 Purpose
Calculates utility fees for invoices based on recorded meter readings and active waivers.

---

## ⚙️ Responsibilities & Behaviors
- **Charge Resolver**: Evaluates utility usage and applies pricing based on the configuration of [[Backend/Models/PropertyUtility|PropertyUtility]] (Metered, Flat, or Shared type).
- **Waiver Checking**: Cross-checks if the utility has a waiver applied on a lease, room, or property scope by calling [[Backend/Models/UtilityWaiver|UtilityWaiver::isWaivedFor]].

---

## 🛠️ Public Methods

### `resolveCharge(UtilityUsage $usage): array`
- **Logic**: Resolves rate, quantity, waiver status, and total amount:
  - If waived ➔ Charge amount is `0.0` and `is_waived = true`.
  - Flat rate billing ➔ Charge equals the fixed property utility rate.
  - Metered billing ➔ Charge equals `amount_used * rate`.
- **Returns**: `array{rate: float, quantity: float, amount: float, is_waived: bool}`.
