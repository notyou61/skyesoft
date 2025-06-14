# Time Interval Standards (TIS)

🧭 **Primary Role**  
To classify each day and time block in a way that supports attendance tracking, order processing, and permit turnaround SLAs, with clear boundaries that exclude weekends and holidays from all time-sensitive calculations.

---

## 📆 Day Types

Each calendar day is classified as one of the following:

| Day Type | Description |
|----------|-------------|
| **Workday** | A regular business day (Mon–Fri) that is not a holiday |
| **Weekend** | Saturday or Sunday |
| **Holiday** | Recognized federal holidays (automatically detected) |

Federal holidays are programmatically detected using the `federalHolidays.php` logic.

---

## ⏰ Time Segments

Each **Workday** is further divided based on operational roles:

### 🏢 Office Staff (7:30 AM – 3:30 PM)

| Time Segment     | Time Range          | Purpose |
|------------------|---------------------|---------|
| **Before Worktime** | 12:00 AM – 7:29 AM   | Pre-shift, early access or scheduling prep |
| **Worktime**        | 7:30 AM – 3:30 PM   | Core business operations, meetings, correspondence |
| **After Worktime**  | 3:31 PM – 11:59 PM  | Follow-ups, admin review, delayed responses |

---

### 🛠️ Shop Staff (6:00 AM – 2:00 PM)

| Time Segment     | Time Range          | Purpose |
|------------------|---------------------|---------|
| **Before Worktime** | 12:00 AM – 5:59 AM   | Prep, load-outs, early material handling |
| **Worktime**        | 6:00 AM – 2:00 PM   | Fabrication, installations, service dispatch |
| **After Worktime**  | 2:01 PM – 11:59 PM  | Cleanup, restock, shop admin work |

---

## ⛔ Exclusion of Weekends & Holidays

Weekend and Holiday time **is not classified into time segments** and is fully excluded from all turnaround time calculations and attendance requirements.

This clean boundary ensures:
- 🕒 SLA compliance is measured only within active business windows
- ✅ Leave tracking and approvals remain fair and consistent
- 📅 Work planning is aligned with staffing expectations

---

## 🔗 Integration

These time segments can be programmatically accessed and used in:
- Smart scheduling
- Permit submission timelines
- Order deadline calculations
- Attendance validation reports
- Real-time SSE monitoring triggers

---

## ⚙️ Dependencies

- `federalHolidays.php` for holiday detection logic
- Server time must be synchronized with local timezone (AZ)
