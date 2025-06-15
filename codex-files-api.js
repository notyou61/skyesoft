// codex-files-api.js
const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();
const CODEX_DIR = path.join(__dirname, "docs", "codex");

// GET all Markdown files
router.get("/", (req, res) => {
  fs.readdir(CODEX_DIR, (err, files) => {
    if (err) return res.status(500).json({ error: "Failed to read Codex directory." });
    const mdFiles = files.filter(f => f.endsWith(".md"));
    res.json(mdFiles);
  });
});

// GET a single Markdown file by name
router.get("/:filename", (req, res) => {
  const fullPath = path.join(CODEX_DIR, req.params.filename);
  if (!fs.existsSync(fullPath)) {
    return res.status(404).json({ error: "File not found." });
  }
  const content = fs.readFileSync(fullPath, "utf-8");
  res.send(content);
});

module.exports = router;
