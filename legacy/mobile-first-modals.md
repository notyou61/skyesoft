# 📱 Mobile-First Modals

## 🧭 Primary Role
To enable rapid, context-aware task execution via modal windows optimized for **mobile and desktop** experiences. These modals support **standard screen usage across the office, shop, and field**, ensuring consistent interaction across environments.

---

## 🧩 Integration Context
This module integrates tightly with:
- ✅ **One-Line Task Engine**: Suggests and opens the correct modal based on user intent.
- ✅ **Real-Time SSE**: Pushes updates or opens modals when action is required.
- ✅ **Core Database**: Reads and writes to structured tables based on modal field mapping.

---

## 🧠 Key Features

### 🪟 Adaptive Modal Forms
- Automatically resizes and rearranges fields for mobile and desktop.
- Dynamically loads relevant inputs based on user role and data type.

### 🧵 AI-Linked Context Insertion
- Prepopulates fields when possible (e.g., “Create PO for Gary Singh” → name/email prefilled).
- Highlights inconsistencies or required fields before submission.

### 🔁 Modal Lifecycles
- **Trigger**: Manually (via button), AI-prompted (via OLT), or real-time event (via SSE).
- **Validate**: Inline checks with visual cues and soft locks.
- **Submit**: Sends updates via Ajax or SSE-compatible post handlers.

---

## 🎯 Purpose
Modals provide a focused, distraction-free interface to:
- Create or update records (Work Orders, POs, Quotes, Contacts)
- Support fast task entry from desktop or mobile devices
- Maintain field parity across operational roles (office, shop, field)

---

## 🛠️ Dev Notes
- Implemented in `modals.js`, triggered by `modalManager.open(type, contextData)`.
- Templated HTML located in `/components/modals/`.
- Must support keyboard navigation and touch optimization.

---

## ✅ Example Use Case

> User types:  
> “Quote site visit for Louie Malaponti at ALC”  
> → Modal auto-triggers: “📋 Schedule Quote Visit”  
> → Prepopulated fields: Name, Company, Phone, Site Address

---

## 📂 Related Modules
- `one-line-task.md`
- `real-time-sse.md`
- `core-database-structure.md`
