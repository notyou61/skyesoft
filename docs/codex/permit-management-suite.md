# Permit Management Suite

### 🔍 Purpose

The **Permit Management Suite** centralizes the creation, submission, tracking, and escalation of sign permit requests. It provides team visibility, SLA tracking, and streamlines coordination with municipalities and vendors.

---

### 👨‍🎓 Users

* **Sales & Account Executives**: Create and submit new permit requests.
* **Permit Team**: Manage intake, submissions, revisions, and status tracking.
* **Managers**: Monitor turnaround time and review escalations.

---

### 📅 Lifecycle Phases

1. **Created**: Submitted by Sales via One-Line Task or modal
2. **Received**: Acknowledged by Permit Team
3. **Submitted**: Sent to municipality
4. **Response Pending**: Awaiting feedback
5. **Revised / Resubmitted**: As needed
6. **Approved** / **Denied**: Final disposition

---

### 🔹 Key Features

* ✉️ Submission logs and timestamps
* ⏰ SLA timers powered by Time Interval Standards
* 🔄 Real-Time SSE updates for live status
* 💡 Smart routing by jurisdiction, permit type
* 🔢 File attachments: drawings, forms, approvals
* 📊 Reporting: approval rates, city response times

---

### ⚖️ Escalation & SLA Enforcement

* Escalation paths defined in Management Escalation Trees
* Late responses flagged for supervisor review
* Missed milestones trigger alert or reassignment

---

### 🌐 Integration

* **Core Database**: Stores jurisdiction, client, and contact metadata
* **File Management**: Archive forms, correspondence, and drawings
* **Mobile-First Modals**: Field staff can view status or upload on-site documents
* **Attendance Suite**: Ensures Permit Team staffing for turnaround tracking

---

### ✏️ Example Workflow

1. Sales enters: *"Sign permit request for 123 Main St in Tempe"*
2. AI parses location, assigns jurisdiction
3. Permit Team receives task, uploads required forms
4. SSE update: *"Submitted to City of Tempe – Awaiting response"*
5. City responds: *"Need updated site plan"*
6. Update entered, AI restarts SLA clock
7. Final result logged, available in audit trail

---

### 🚀 Objective

Transform a traditionally paper-heavy and opaque process into a transparent, time-sensitive, and traceable digital workflow that integrates with all major operations at Christy Signs.
