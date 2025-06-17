# Core Database Structure

🧭 **Primary Role**  
To serve as the foundational data layer for all Skyesoft modules — enabling fast, secure, and relational access to users, tasks, permits, schedules, files, finances, and workflows. The database is the backbone that powers both real-time SSE and historical auditing across all interfaces.

---

## 🧱 Architectural Model

Skyesoft's database is structured in two parts:

### 1. **Static Reference Tables**  
Loaded once and rarely changed. These include fixed lookup values such as:

| Table Name         | Purpose                                 |
|--------------------|-----------------------------------------|
| `states`           | U.S. state abbreviations & names        |
| `cities`           | Major cities used in permit tasks       |
| `job_types`        | Categories of work (e.g., Install, Survey) |
| `departments`      | Teams using the system (e.g., Shop, Office) |

Data from these tables powers dropdowns, filters, and standard workflows.

---

### 2. **Updatable Data Tables**  
These track active business operations:

| Table Name           | Purpose                                  |
|----------------------|------------------------------------------|
| `clients`            | Contact info and associations            |
| `orders`             | Job tickets including sign and install   |
| `permits`            | Municipality submittals and responses    |
| `tasks`              | Granular unit actions tied to schedules  |
| `attendance`         | Staff time-ins and outs                  |
| `messages`           | SSE-tracked status logs and memos        |

These tables are frequently read, updated, and extended as work progresses.

---

## 🧩 Sample Table: `permits`

| Field          | Type       | Description                            |
|----------------|------------|----------------------------------------|
| `id`           | INT (PK)   | Auto-increment ID                      |
| `client_id`    | INT (FK)   | Linked to `clients` table              |
| `municipality` | VARCHAR    | Jurisdiction of the request            |
| `status`       | ENUM       | 'Pending', 'Submitted', 'Approved'     |
| `submit_date`  | DATE       | When sent                              |
| `approval_date`| DATE       | When approved (if known)               |

---

## 🔄 Real-Time Sync + SSE

Skyesoft’s architecture relies on syncing database changes in real time:

- ✅ New records are pushed via Server-Sent Events (SSE)
- 🔄 Changes in `tasks`, `permits`, and `orders` trigger timeline updates
- 📤 External requests (email, uploads, etc.) post directly to the database

---

## 🧠 Smart Forms Integration

AI-driven inputs like the **One-Line Task Prompt** interact directly with the database:

- 📥 "Meet with Louie @ ALC Group" → creates a `contact` + `task`
- 📝 "New permit for Queen Creek – 6/17" → opens a record in `permits`
- 📁 File drop → logs metadata in `files` and links to `orders`

---

## 🔐 Data Policy

- Hosted on secure MySQL via GoDaddy
- Time zone set to Arizona (no DST shift)
- All changes logged for audit trail integrity

---

## 📌 Summary

The Skyesoft database is not just storage — it's an active engine powering all scheduling, alerting, and decision logic. Built to scale and structured to adapt, it ensures every action has traceable, actionable data behind it.