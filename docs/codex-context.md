# 🧭 Codex Context: Skyesoft Platform Overview

## 🎯 Purpose

This codex exists to guide AI and human collaborators through the structure, strategy, and intent of the Skyesoft platform. Skyesoft is not just code — it's a living, modular system enabling streamlined operations for industries like signage, built from real workflows.

## 🧠 What Skyesoft *Is*

Skyesoft is a unified field operations and management platform custom-built for real-world businesses, beginning with the signage industry. It’s not a "developer tool" or "SDK" — it's a **real-time, document-driven, modular platform** that centralizes workflows and scales across departments like Sales, Design, Field, and Permitting.

Skyesoft is driven by:

- ✅ **Modular JSON-first architecture** for document rendering and task automation  
- ✅ **Server-Sent Events (SSE)** for live updates between office and field  
- ✅ **Field usability** via Bootstrap modals, touch-optimized UI, and GPS-based actions  
- ✅ **No vendor lock-in** – open stack, portable, and self-hosted

## ❌ What Skyesoft *Is Not*

- ✘ Not a CMS (though it centralizes structured content)  
- ✘ Not a CRM (though it tracks sales and client data)  
- ✘ Not a SaaS (there are no licenses or cloud dependencies required)  
- ✘ Not dependent on third-party APIs like Helius, Jupiter, or Raydium

## 🧱 Codex vs Platform

This repo contains a **"codex" layer** — designed for AI integration, document generation, and knowledge dissemination. That layer draws its structure from:

- `docs/master_content.json`: Source-of-truth JSON for all docs  
- `render.js`, `generate_pdf.js`: Converts JSON into stylized HTML/PDF formats  
- Proposal types like: `"lead_or_sell"`, `"memo"`, `"use_case"`  

But underneath that is **Skyesoft the Platform** — a working, interactive tool in `legacy/azsignpermits/` that powers field operations today. That’s the layer being modernized, AI-augmented, and continuously refined.

## 📦 Key Modules

- `/docs/` – Structured document definitions (Codex layer)
- `/render.js` – HTML generation from codex data
- `/legacy/azsignpermits/` – Live, working field software
- `/permitTasks.js` – Task logic for field workers and sales
- `/server-scripts/` – PHP/SSE/PDF generation logic (to be refactored)

## 🚧 Future Goals

- ✨ Rebuild core modules in modern JavaScript or TypeScript
- 🧩 Modularize permit/project/task handling
- 📄 Integrate live codex-to-pdf pipeline with templates
- 🧠 Introduce AI-guided actions in proposal writing, site assessment, and permit submittals

---

*Last updated: June 13, 2025*