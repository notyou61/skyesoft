# 📘 Skyesoft Codex

Welcome to the **Skyesoft Codex** — a curated collection of core modules and design philosophies that define our internal systems and user experience platform. This document provides an overview and quick reference to all Codex components currently in place.

---

## 🔖 Codex Modules Index

| Module                                                          | Purpose                                                             |
| --------------------------------------------------------------- | ------------------------------------------------------------------- |
| [Time Interval Standards](./time-interval-standards.md)         | Defines day types and time blocks for accurate turnaround tracking. |
| [Real-Time SSE](./real-time-sse.md)                             | Establishes server-sent event pipelines for live system updates.    |
| [Core Database Structure](./core-database-structure.md)         | Centralized schema that powers all dynamic modules.                 |
| [One-Line Task](./one-line-task.md)                             | AI-enhanced input that recognizes task types from free-form text.   |
| [File Management](./file-management.md)                         | Architecture for upload, classification, and lifecycle of files.    |
| [Mobile-First Modals](./mobile-first-modals.md)                 | Responsive UI templates for field, office, and dashboard actions.   |
| [Attendance Suite](./attendance-suite.md)                       | Tracks presence, absences, exceptions, and generates insights.      |
| [Office Bulletins](./office-bulletins.md)                       | Central notice system for daily updates and interdepartmental news. |
| [Financial Control Suite](./financial-control-suite.md)         | Tracks job costing, budget flags, and financial alerts.             |
| [Permit Management Suite](./permit-management-suite.md)         | Full workflow and SLA tracking for sign permits.                    |
| [Service Management Suite](./service-management-suite.md)       | Handles service orders, maintenance, and support dispatch.          |
| [Management Escalation Trees](./management-escalation-trees.md) | Rules and paths for auto-escalating unresolved or overdue issues.   |

---

## 🧭 Vision

The Codex is not just documentation — it is the **operational DNA** of Skyesoft. Each module integrates with others, forming a reliable and extensible framework for:

* Streamlined communication
* Smart task automation
* SLA enforcement
* Human-friendly interfaces
* Audit-ready traceability

---

## 🛠️ Implementation Notes

* All `.md` files reside in the `/docs/codex/` folder in the main repo.
* Changes to Codex modules should be committed via Pull Request with a versioned tag.
* For technical integration or deployment architecture, refer to `core-database-structure.md` and `real-time-sse.md`.

---

## 🧩 Extending the Codex

New components or revisions can be proposed using the One-Line Task input format, or by submitting drafts to `codex@skyesoft.local`.

> **Reminder:** The Codex is a living framework. Changes are welcome, but all updates must honor system cohesion and user-first logic.

---

*Last updated: June 2025*
