# 🏢 Feature Spec: Property Management

- **Context Link**: [[Features/Property]]
- **Associated Models**: [[Backend/Models/Property]], [[Backend/Models/Unit]], [[Backend/Models/PropertyUtility]]

---

## 🎯 Feature Purpose
Allows landlords and managers to create properties, set up units (rooms), and configure metered or flat-rate utilities.

---

## ⚙️ Key Operations & Flows
- **Property Creation**: Enters address line, property type (Apartment, Condo, House, Villa), and lists amenities.
- **Unit Configuration**: Rooms are registered with room number, floor number, type, rent amount, and default due date.
- **Utility Configuration**: Rates for metered variables (water cubic meters, electricity kW) are defined per property.

---

## 🛡️ Business Rules
- **SaaS Plan Cap Checking**: Unit creation asserts the landlord's current count is within plan caps (`SubscriptionService::assertWithinUnitCap`).
- **Unique Room Numbers**: Room numbers must be unique within a single property.
- **Rate Scoping**: Utility rates apply only to units inside the host property and are isolated from the landlord's other assets.
