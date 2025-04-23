# 📘 Skyesoft Directory Structure & Purpose

Welcome to the **Skyesoft** project. This `README.md` provides a clear, updated overview of the folder structure, content management workflow, and core principles for working within this proposal-driven, modular documentation system.

---

## 🧠 Central Source of Truth

All proposals, use cases, and memos are structured in:

```
docs/master_content.json
```

This file serves as the **canonical record** of all Skyesoft documents. It includes:

- A `meta` block: ID, title, tags, status, author, version, etc.
- A `content` block: Headings, sections, body content
- References to supporting file formats: `.md`, `.pdf`, `.html`

---

## 📂 Root Folder Structure

```
skyesoft/
├── docs/
│   ├── master_content.json                 # Central source of truth
│   ├── assets/                             # CSS and visual styling
│   ├── memos/                              # Internal communication
│   ├── presentations/                      # Slide decks, .html viewers
│   ├── proposals/
│   │   ├── lead_or_sell/                   # Leadership workflow doc
│   │   └── branch_office/                  # 🆕 East Valley Branch Proposal (added 04/23)
│   ├── use_cases/
│   │   └── skyesoft_scheduling/            # Real-world workflow context
│   └── viewer/                             # Web viewer template & twemoji
├── tools/                                  # Scripts for rendering and maintenance
├── .gitignore
├── README.md
```

---

## 🛠 Tools

```
tools/
├── emoji_embedder.js
├── emoji_render_to_pdf.js
├── generate_tree.js
├── render_pdf_chrome.js
├── organize-skyesoft.ps1
```

These tools assist with:
- Markdown ➜ HTML ➜ PDF generation
- Tree structure visualization
- Directory cleanup and folder reshaping
- Embedding emojis and templating exports

---

## ✅ Best Practices

- 📁 Store all structured documents in `docs/`, organized by type and ID
- 🔗 Register every entry in `master_content.json`
- 🧩 Place `.md`, `.pdf`, and optional `.html` in matching subfolders
- 🗃 Use `.keep` files to preserve folder scaffolding across machines
- 🌿 Use named snapshot branches (e.g. `office-snapshot-*`) before pulling or reorganizing
- 📌 Commit changes with descriptive messages and sync before switching locations

---

## 🧑‍💼 Maintained By

**Steve Skye**  
Version: v1.1  
Last updated: April 23, 2025

To contribute or add a new document:
- Update `master_content.json`
- Add your files to the appropriate folder
- Commit using the format: `"Add [Title] [Type] - [Date]"`