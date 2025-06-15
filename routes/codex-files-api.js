const express = require("express");
const fs = require("fs");
const path = require("path");
const router = express.Router();

const FILES_DIR = path.join(__dirname, "../docs/codex");

router.get("/", (req, res) => {
  fs.readdir(FILES_DIR, (err, files) => {
    if (err) {
      return res.status(500).json({ error: "Unable to read Codex directory." });
    }
    const markdownFiles = files.filter(file => file.endsWith(".md"));
    res.json(markdownFiles);
  });
});

module.exports = router;
