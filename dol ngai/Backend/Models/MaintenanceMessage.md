# 💬 MaintenanceMessage Model

- **File Path**: [MaintenanceMessage.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/MaintenanceMessage.php)
- **Table Name**: `maintenance_messages`
- **Related Feature**: [[Features/Maintenance]]

---

## 🎯 Purpose
The `MaintenanceMessage` model records chat notes and communication exchanges between landlord management and tenants regarding a specific [[Backend/Models/MaintenanceRequest|MaintenanceRequest]].

---

## ⚙️ Responsibilities & Behaviors
- **Message Log**: Stores message body, sender reference, and parent request ID.

---

## 🔗 Relationships
- **[[Backend/Models/MaintenanceRequest|request()]]** (BelongsTo): Parent maintenance issue request.
- **[[Backend/Models/User|sender()]]** (BelongsTo): The user (landlord or tenant) who sent the message.
