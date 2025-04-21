# 📘 Skyesoft Directory Structure & Purpose

Welcome to the **Skyesoft** project. This README provides a clear overview of the project folder layout, what each directory is for, and how it supports proposal-driven development, JSON document rendering, and modular UI tools.

---

## 📂 Root Folders

```
skyesoft/
├── assets/
├── config/
├── docs/
├── src/
├── .gitignore
├── README.md
```

### 📁 `assets/`
Static media and file templates. Use this for logos, image assets, or starter files that support proposals and viewers.

### 📁 `config/`
Reserved for configuration files or environment-specific constants, such as settings for viewers, file naming conventions, or automated build tools.

### 📁 `docs/`
Main location for all documentation, structured content, and rendered output.

```
docs/
├── pdf_output/          # Generated proposal PDFs or export files
├── proposal/            # Proposal content and data (JSON + MD)
│   └── json/            # Structured JSON versions of proposals
├── use_cases/           # Future or current real-world use case documents
└── viewer/              # Shared HTML/CSS viewer to render JSON proposals
```

- `json/`: Machine-readable versions of proposals and use cases
- `viewer/`: Contains `viewer.html` and `proposal.css`, the UI to load and display proposal data

### 📁 `src/`
Internal scripts and tooling for handling JSON files, CLI interactions, and PDF generation.

```
src/
├── cli_tools/           # Developer tools and scripts
├── json_parser/         # Code for processing and validating JSON content
└── pdf_generator/       # Logic to convert JSON to styled PDF output
```

---

## ✅ Best Practices in This Repo
- Keep all JSON documents versioned and inside `docs/proposal/json/`
- Use `index.json` to track available proposals or use cases
- Keep viewer assets shared between `proposal/` and `use_cases/`
- Follow the naming format: `type_title_vX.X.json`
- Future: Introduce changelogs and contributors per proposal if needed

---

## 📄 Proposal Types
- **Proposals** = Formal structured ideas, plans, and workflows (leadership, product, system-wide)
- **Use Cases** = Supporting or contextual real-world examples, often referencing a proposal

---

## 👤 Maintained by
**Steve Skye**  
Version: v1.1  
Date: April 21, 2025

For questions, contributions, or version updates, please reach out or submit a proposal entry in the proper `json/` folder.

