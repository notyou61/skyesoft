# Skyesoft Proposal Renderer

This project renders structured business artifacts (e.g., proposals, use cases, workflows) from a centralized `master_content.json` into styled HTML outputs, suitable for both on-screen review and PDF export.

---

## 🗂 Project Structure

```
skyesoft/
├── docs/
│   ├── assets/
│   │   ├── template.html      # HTML template (GitHub Markdown style)
│   │   ├── style.css          # Custom styling enhancements
│   └── master_content.json    # Source of truth for all artifacts
├── output/
│   └── lead_or_sell.html      # Rendered HTML output
├── render.js                  # Rendering logic to transform JSON into HTML
├── README.md                  # Project overview and instructions
```

---

## 🛠 How It Works

1. **JSON Input**  
   All artifacts (proposals, use cases, etc.) are stored in `master_content.json` under the `artifacts` key. Each artifact has `meta` and `content.sections`.

2. **HTML Rendering**  
   `render.js` reads the JSON and inserts the parsed content into `template.html`, producing a clean, readable HTML file in the `/output` folder.

3. **GitHub Markdown Styling**  
   The template uses the [GitHub Markdown CSS](https://cdnjs.com/libraries/github-markdown-css) for a familiar, professional appearance.

---

## 🚀 Usage

```bash
node render.js lead_or_sell
```

- Generates: `output/lead_or_sell.html`
- Replace `lead_or_sell` with any valid artifact ID from the JSON.

To automate this (optional):
```bat
@echo off
node render.js %1
pause
```
Save as `build-proposal.bat` and run with:
```bash
build-proposal.bat lead_or_sell
```

---

## 🎨 Styling Notes

- Layout: Centered, max-width, printable with 1-inch margins.
- Lists:
  - Top-level items can include emoji headers (e.g., 🚫, 📋)
  - Sub-items indent as bullets
- Typography: Clean, modern sans-serif stack.

---

## ✅ Current Artifacts

- `lead_or_sell` – Leadership and compensation reform proposal
- `skyesoft_proposal` – Smart workflow system overview
- `skyesoft_use_case_geo_service` – Geo-tagged service exit protocol

---

## 📦 Dependencies

- Node.js (≥ v16)
- GitHub Markdown CSS (via CDN)

---

## 📍 Author

**Steve Skye**  
Christy Signs | Phoenix, AZ  
Project Lead – Skyesoft Platform