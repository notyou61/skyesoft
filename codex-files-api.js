const fs = require("fs");
const path = require("path");
const express = require("express");

const router = express.Router();
const CODEX_DIR = path.join(__dirname, "docs/codex");

router.get("/", (req, res) => {
  fs.readdir(CODEX_DIR, (err, files) => {
    if (err) return res.status(500).json({ error: "Unable to read codex directory." });
    const markdownFiles = files.filter(f => f.endsWith(".md"));
    res.json(markdownFiles);
  });
});

router.get("/:filename", (req, res) => {
  const fileName = req.params.filename;
  const fullPath = path.join(CODEX_DIR, fileName);

  if (!fs.existsSync(fullPath)) {
    return res.status(404).json({ error: "File not found." });
  }

  const content = fs.readFileSync(fullPath, "utf-8");
  res.send(content);
});

module.exports = router;