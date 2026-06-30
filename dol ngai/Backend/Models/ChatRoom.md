# 💬 ChatRoom Model

- **File Path**: [ChatRoom.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/ChatRoom.php)
- **Table Name**: `chat_rooms`
- **Related Feature**: [[Features/Notification]]

---

## 🎯 Purpose
The `ChatRoom` model represents a messaging channel. It can be a direct chat between a landlord and tenant, or linked polymorphically to a specific system entity (e.g. maintenance request, rental contract).

---

## ⚙️ Responsibilities & Behaviors
- **Polymorphic Linking**: Uses a polymorphic morph map relation (`related()`) to link the room to business models (e.g., `App\Models\MaintenanceRequest`).
- **Participant Mapping**: Manages participants through a many-to-many relationship, storing read state (`last_read_at`) and mute preferences.

---

## 🔗 Relationships
- **createdBy()** (BelongsTo): The [[Backend/Models/User|User]] who initialized the chat room.
- **related()** (MorphTo): The polymorphic target (e.g. maintenance requests).
- **participants()** (BelongsToMany): Users participating in this chat channel (stored in `chat_room_participants` table).
- **[[Backend/Models/ChatMessage|messages()]]** (HasMany): Log of messages posted to the channel.
