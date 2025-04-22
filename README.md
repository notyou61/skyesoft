# 📘 Skyesoft Directory Structure & Purpose

Welcome to the **Skyesoft** project. This README provides a clear overview of the project folder layout, how documents are managed using a centralized JSON object, and how this supports proposal-driven development, modular UI tools, and exportable content formats.

---

## 🧠 Central Source of Truth

All structured documents (proposals, use cases, memos) are defined in:

```
docs/master_content.json
```

This file contains a `documents[]` array where each object includes:
- A `meta` block (title, id, author, version, tags, status, etc.)
- A `content` block (sections, headings, body, etc.)

This file powers all renderers, viewers, and export tools.

---

## 📂 Root Folders

```
skyesoft/
├── docs/
│   ├── master_content.json
│   ├── proposals/
│   │   ├── lead_or_sell/
│   │   │   ├── lead_or_sell.md
│   │   │   ├── lead_or_sell.pdf
│   │   │   ├── lead_or_sell.html
│   │   │   └── lead_or_sell.json
│   │   └── skyesoft_proposal/
│   │       └── skyesoft_proposal.json
│   ├── use_cases/
│   │   └── skyesoft_scheduling/
│   ├── memos/
│   ├── presentations/
│   └── viewer/
│       ├── proposal-template.html
│       └── twemoji.min.js
├── .gitignore
├── README.md
├── package.json
├── package-lock.json
```

---

## ✅ Best Practices

- 📁 Store all structured content in `master_content.json`
- 🧩 Add supporting PDFs, Markdown, or HTML to a matching folder by ID
- 🔎 Reference documents by `meta.id` (e.g., `lead_or_sell`)
- 🗂 Use the `type` field in each document to distinguish proposals, use cases, memos, etc.

---

## 📄 Document Types

- **Proposals** = Formal structured workflows, frameworks, or platform designs
- **Use Cases** = Contextual examples supporting proposals
- **Memos** = Team-facing summaries or communications
- **Presentations** = Viewer-ready slides or walkthroughs

---

## 👤 Maintained by
**Steve Skye**  
Version: v1.1  
Last updated: April 22, 2025

For updates or new entries, append to `master_content.json` and add your supporting content in the appropriate folder.
