# 💬 ChatMessage Model

- **File Path**: [ChatMessage.php](file:///home/tms/Desktop/dol-ngai/rentwise/app/Models/ChatMessage.php)
- **Table Name**: `chat_messages`
- **Related Feature**: [[Features/Notification]]

---

## 🎯 Purpose
The `ChatMessage` model represents a single text or file message sent within a [[Backend/Models/ChatRoom|ChatRoom]] channel.

---

## ⚙️ Responsibilities & Behaviors
- **Message Content Storage**: Tracks the message body text, optional file attachment URLs, message type (Text, File, System), and read status.

---

## 🔗 Relationships
- **[[Backend/Models/ChatRoom|room()]]** (BelongsTo): Parent chat room.
- **[[Backend/Models/User|user()]]** (BelongsTo): The sender [[Backend/Models/User|User]] account.
