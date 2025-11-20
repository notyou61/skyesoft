# 🔐 Login/Logout & Chat Session Suite

## 🎯 Purpose
To define how user authentication, chat session state, and action logging are managed in the Skyebot™ platform, ensuring secure usage and reliable historical tracking.

## 📌 Key Features
- User authentication required for bot access (session cookie enforced)
- Skyebot is only visible and enabled after successful login
- Each chat session receives a unique session ID (incremental per user/device)
- Every chat entry is logged with timestamp, user ID (contacts PK), session ID, and action PK (if applicable)
- Logout can be triggered agentically via Skyebot or main UI
- Session and chat logs are centrally stored as structured JSON

## 🗝️ Authentication & Visibility
- On successful login, a secure session cookie is set and user data loaded
- Bot remains hidden until authentication is confirmed
- On logout, session cookie is cleared and bot is disabled/hidden

## 💬 Chat Logging Structure
- Each session log contains:
  - `session_id`: Incremental or GUID per session
  - `user_id`: Reference to contacts PK
  - `timestamp`: Unix time (seconds)
  - `role`: "user" or "assistant"
  - `message`: Text content
  - `action_id`: Nullable PK from actions table if action triggered

### 🏁 Example Entry
```json
{
  "session_id": 42,
  "user_id": 17,
  "timestamp": 1753072843,
  "role": "assistant",
  "message": "You have been logged out.",
  "action_id": 7
}
