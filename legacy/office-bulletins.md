# Office Bulletins

### 📢 Purpose

Office Bulletins are a centralized communication feature designed to keep all departments informed of critical updates, general announcements, and timely reminders. It ensures consistent messaging across shop, office, and field staff, with visibility controls for targeting specific roles or locations.

---

### 📆 Bulletin Types

* **Global** — Shown to all users across all platforms
* **Departmental** — Office, Shop, Field, or custom roles
* **Urgent** — Highlighted with special formatting and sent via additional channels (email, SMS)
* **Event-Based** — Tied to specific dates (e.g., inspections, holidays)

---

### 📁 Storage & Display

* Stored in the **Core Database** with fields for:

  * Title
  * Body (Markdown-compatible)
  * Department or User Role
  * Priority Level
  * Start/End Dates
* Displayed via **Mobile-First Modals** on login or dashboard refresh
* Expired bulletins automatically archived

---

### ✅ Features

* Markdown support for formatting and links
* Pinning high-priority bulletins to dashboards
* Read receipt tracking (optional)
* Permission-based editing and publishing via Admin Panel
* Bulletin history and archive view per user

---

### 📅 Usage Examples

* "City Inspector scheduled on-site Monday, 8am"
* "Holiday schedule reminder: Office closed July 4th"
* "New permit application form now available"
* "Shop gate to remain locked after 6pm"

---

### 🌐 Access Points

* **Office Dashboard**: Bulletin bar + notifications
* **Shop Kiosk View**: Full-screen bulletin carousel
* **Field Tablets**: Pop-up modals synced with logins

---

### 🚀 Objective

To eliminate communication gaps, reinforce operational updates, and ensure time-sensitive announcements are clearly delivered to the appropriate audience.

---

### 🔧 Notes

* Integrated with **Time Interval Standards** for start/expire logic
* Smart filtering: Only relevant bulletins shown based on user metadata
* Potential future feature: Comment or acknowledgment buttons
