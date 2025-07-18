#!/bin/sh
node scripts/bump-version.js
git add assets/data/version.json docs/codex/codex-version.md
git commit -m "🔁 Auto-bump version metadata" --no-verify || true
