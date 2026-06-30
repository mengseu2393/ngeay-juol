# 🔧 Feature Spec: Maintenance Requests

- **Context Link**: [[Features/Maintenance]]
- **Associated Models**: [[Backend/Models/MaintenanceRequest]], [[Backend/Models/MaintenanceMessage]], [[Backend/Models/User]]

---

## 🎯 Feature Purpose
Enables tenants to submit maintenance issues (AC breakdown, plumbing leak, etc.) and allows landlords to update progress and chat directly with tenants to resolve issues.

---

## ⚙️ Key Operations & Flows
- **Request Creation**: A tenant uploads photos of a broken item and describes the problem.
- **Priority Assessment**: Landlords prioritize requests (Low, Medium, High).
- **Communication Log**: Both parties post updates directly to the issue message log.

---

## 🛡️ Business Rules
- **Access Scoping**: A landlord or manager can only view, update, or chat on maintenance requests linked to properties they manage.
- **Support Read Bypass**: Support agents can read all maintenance logs globally to troubleshoot issues.
- **Tenant Isolation Bounds**: Tenants can only view or create requests within their current lease context (`rental_id` matching their active rental record).
