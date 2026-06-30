# 🔔 Feature Spec: Notifications & Messaging

- **Context Link**: [[Features/Notification]]
- **Associated Models**: [[Backend/Models/ChatRoom]], [[Backend/Models/ChatMessage]], [[Backend/Models/User]]

---

## 🎯 Feature Purpose
Provides communication channels and real-time internal notifications for tenants, landlords, and managers.

---

## ⚙️ Key Operations & Flows
- **Direct Message Channels**: Landlords can open direct communication channels with tenants to discuss billing, issues, or lease updates.
- **Polymorphic System Contexts**: Chat rooms can link directly to entities (like a specific [[Backend/Models/MaintenanceRequest|MaintenanceRequest]] or [[Backend/Models/Rental|Rental]] lease) so the messaging log remains contextualized.
- **Read-receipt tracking**: Tracks when a participant last checked the chat channel (`last_read_at` on pivot metadata).

---

## 🛡️ Business Rules
- **Access Scoping**: A user must be an active participant or a platform support agent to view chat rooms and read/post messages.
- **Tenant Scope Bounds**: Tenants are restricted to chat channels in which they are explicitly registered as participants.
- **Deactivated Users**: Suspended/inactive users are blocked from sending or receiving messages.
