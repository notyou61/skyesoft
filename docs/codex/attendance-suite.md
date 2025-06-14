# 🕒 Attendance Suite

## 🎯 Purpose
To track user attendance behaviors, time in/out, overtime, and absences using segmented workday rules. Designed for field techs, office staff, and shop workers.

## 📌 Key Features
- Auto-classification by day type (Workday, Weekend, Holiday)
- Segmented time blocks: Before Worktime, Worktime, After Worktime
- Shift-specific logic for shop (6:00–2:00) and office (7:30–3:30)
- Manual and automatic entries via device punches or server logs
- Integration with Time Interval Standards (TIS)

## 📊 Data Points Tracked
- Clock-in/out time per user
- Location/device of punch (optional)
- Duration within each segment (e.g., Worktime)
- Missed punches / late entries
- PTO, sick time, holidays

## 🧠 Smart Behaviors
- Automatically resolves entry overlaps
- Flags outliers and missed punches
- Can trigger Management Escalation Tree rules if attendance is habitual concern

## 🔌 Integration Points
- Links with Order System for assignment-based tracking
- Works with Mobile Modals for on-field punch-in
- Real-time updates via SSE (Server-Sent Events)

## 🏁 Example Entry
```json
{
  "user": "msmith",
  "date": "2025-06-13",
  "in": "07:35",
  "out": "15:45",
  "worktime_minutes": 480,
  "early_minutes": 0,
  "late_minutes": 5,
  "location": "Shop Tablet"
}
```

---

Once saved, you can use this terminal command **inside your skyesoft repo** to stage and commit later:

```bash
git add ./docs/codex/attendance-suite.md
```

Let me know when ready to proceed to the next module or if you'd like me to prep the commit script for everything so far.
