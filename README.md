# 🌐 Skyesoft – Smart Workflow Platform

**Skyesoft** is a modular, real-time operations system designed for field-based industries—starting with the signage sector. It streamlines workflows, empowers teams, and scales across departments like Sales, Design, Service, and Permitting.

---

## 🚀 Executive Overview

Skyesoft replaces fragmented tools (email, spreadsheets, CRMs) with a unified, AI-enhanced platform. It automates daily tasks, integrates real-time updates using Server-Sent Events (SSE), and adapts to your company’s structure—no recurring license fees or vendor delays.

- 📡 Built on modern open-source stacks (HTML, JS, PHP, SSE)
- 🧠 Uses centralized content stored in JSON for document-driven development
- 📱 Mobile-first UI for field reps, real-time dashboards for managers

---

## 🧠 Architecture & Core Logic

### 🔐 Master Content System

All documents—proposals, use cases, memos—are stored in:

```
docs/master_content.json
```

Each entry includes:
- `meta`: ID, title, author, tags, version, etc.
- `content`: Array of section blocks (`title`, `icon`, `body`, etc.)

This file is the **source of truth** for all exports and renderers.

---

## 📂 Folder Structure

```
skyesoft/
├── docs/
│   ├── master_content.json       # 📘 Central source of all artifacts
│   ├── assets/
│   │   ├── template.html         # 🧩 Markdown-styled rendering template
│   │   ├── style.css             # 🎨 Print and layout enhancements
│   │   └── github-markdown.css   # 🧱 GitHub-flavored markdown styling
│   ├── proposals/
│   │   └── lead_or_sell/         # 📝 Rendered outputs per proposal
│   └── presentations/            # 🖥️ Future presentation files
├── output/                       # 📤 Exported HTML files
├── render.js                     # 🛠️ JSON → HTML renderer
├── generate_pdf.js               # 📄 HTML → PDF generator
├── export.js                     # 🔁 Proposal/task automation (future)
├── build-proposal.bat            # 🖱️ Batch wrapper for rendering pipeline
├── package.json / lock           # 📦 Node dependencies
└── README.md                     # 📚 This file
```

---

## 📄 Document Types

- **Proposals**: Strategic plans, frameworks, or leadership actions
- **Use Cases**: Real-world implementation flows and task scenarios
- **Memos**: Internal communications and team directives
- **Presentations**: Slide-based overviews for meetings or training

---

## 🛠 Key Features (Planned & Live)

- ✅ Real-time job dashboards
- ✅ Proposal-to-PDF automation
- ✅ Geo-verified site check-ins
- ✅ Contact parsing via AI
- 🧪 Ordinance scanning and form autofill (coming soon)
- 📊 Permit + KPI analytics boards (coming soon)

---

## 🌍 Deployment & Usage

- All rendering uses `master_content.json`
- Run `node render.js lead_or_sell` to generate HTML
- Customize presentation via `template.html` + `style.css`

---

## 📢 Maintained By

**Steve Skye**  
Christy Signs | Phoenix, AZ  
_Last updated: April 2025_

---

## 💡 Contribute / Extend

For new proposals:
- Add a new object to `master_content.json`
- Place any Markdown, PDF, or rendered HTML inside `docs/proposals/{id}/`
- Use the provided tools (`render.js`, `build-proposal.bat`) to generate outputs
