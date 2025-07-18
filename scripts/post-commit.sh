#!/bin/sh
echo "📦 Running post-commit hook..."
node scripts/bump-version.js

# Stage updated version files for next commit (do not commit inside the hook!)
git add assets/data/version.json docs/codex/codex-version.md

echo "✅ post-commit hook completed (staged version bump, not committed)."

