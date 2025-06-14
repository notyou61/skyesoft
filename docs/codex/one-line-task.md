# 🧠 One-Line Task (OLT) Input Recognition

## 📌 Purpose
To provide a natural language entry point that intelligently interprets user input and performs the most context-appropriate action—be it database updates, task creation, record lookups, or communication triggers.

---

## 🎯 Primary Role
To empower users to interact with the Skyesoft platform using freeform one-liners. These are parsed using lightweight NLP logic and pattern recognition to identify the most likely intention behind the text.

---

## ⚙️ How It Works

### 1. **Text Parsing**
- Input is scanned for:
  - Keywords (e.g., "quote", "repair", "call", "submit")
  - Named entities (e.g., person names, companies, phone numbers, addresses)
  - Dates and times (relative or absolute)

### 2. **Intent Detection**
- Based on content type and structure, the system attempts to match the prompt to known action templates such as:
  - **New Contact** — If email, phone, or name-like strings are detected
  - **Work Order or Quote** — If sign types, addresses, and service terms are used
  - **Permit Request** — If jurisdiction or municipal terms are found

### 3. **Smart Suggestions (Optional AI Prompt)**
- If intent is ambiguous, Skyesoft prompts the user:  
  _"Did you mean to create a new contact? Start a quote? Schedule a job?"_

---

## ✏️ Examples

| Input | Recognized Action |
|-------|-------------------|
| `Jim Flanigan at ALC Group, 816-421-8335` | ➕ Add new contact |
| `Schedule reinstall for Yogurtology next Wednesday at noon` | 📅 Create task with date/time |
| `Sign permit follow-up, call Louie` | ☎️ Call task with reference note |
| `Start quote for 810 S 56th Ave, monument sign repair` | 📝 Begin quote form with prefilled fields |

---

## 🧩 Integration Points

- **Database Modules**: Contact, Quotes, Tasks
- **Time Interval Standards**: For due date logic
- **Permit Suite**: Jurisdiction lookup if applicable
- **Attendance + Escalation Trees**: If SLA rules apply

---

## 🛠️ Future Enhancements

- Auto-tagging entities and locations
- Suggesting next steps (e.g., _"Would you like to notify the shop?"_)
- Backfill from prior activity threads to infer intent

---

## 🧭 Strategic Importance

This feature removes barriers to entry and allows front office staff and sales reps to initiate actions **without navigating deep menus**—accelerating workflows and boosting adoption across the organization.
