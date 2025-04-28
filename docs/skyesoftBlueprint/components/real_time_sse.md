# 🔄 Real-Time Server-Sent Events (SSE) Updates

## 🏷️ Purpose
Enable real-time data streaming to all users without needing page reloads — so actions taken by one user immediately reflect to all.

## 🛠️ Technical Details
- SSE Endpoint: `server-sent-events.php`
- Event Channels: CRUD Logs, File Uploads, Login Events
- Broadcast to all connected sessions
- Stream Delta Updates Only (not full page reloads)

## 🎯 Key Features
- See new orders appear instantly
- Instant alerts for new applications or permit changes
- Real-time attendance tracking

## 🏗️ Implementation Notes
- Server connection keep-alive every 30 seconds
- Lightweight JSON payloads to minimize server strain
- Built-in reconnect attempts if stream fails