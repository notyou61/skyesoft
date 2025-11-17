#!/bin/bash
# Skyesoft Quarantine Script – Full Recursive (Safe Mode)

ROOT="."
QUAR="./quarantine"
REPORT="./quarantine_report.txt"

# Ensure quarantine folder exists
mkdir -p "$QUAR"

echo "Skyesoft Quarantine Script – Safe Mode" | tee "$REPORT"
echo "Scanning all files recursively..." | tee -a "$REPORT"

# Known Keep patterns (expandable)
KEEP_PATTERNS=(
    "./index.html"
    "./officeBoard.html"
    "./.gitignore"
    "./.gitattributes"
    "./.nojekyll"
    "./README.md"
    "./package.json"
    "./package-lock.json"
    "./favicon.ico"
    "./assets/css/"
    "./assets/images/"
    "./assets/data/"
    "./assets/js/skyebot.js"
    "./api/"
    "./quarantine/"
)

# Function: Check if path is in known keep patterns 
is_keep() {
    local file="$1"
    for kp in "${KEEP_PATTERNS[@]}"; do
        if [[ "$file" == $kp* ]]; then
            return 0
        fi
    done
    return 1
}

# Confirmation prompt before irreversible moves
read -p "⚠️  Move all unknown files to quarantine? (yes/no): " confirm
if [[ "$confirm" != "yes" ]]; then
    echo "❌ Operation cancelled."
    exit 1
fi

# Main loop
while IFS= read -r -d '' f
do
    if is_keep "$f"; then
        echo "🛡️ KEEP   → $f" | tee -a "$REPORT"
    else
        echo "📦 QUARANTINE → $f" | tee -a "$REPORT"
        mv "$f" "$QUAR/"
    fi
done < <(find "$ROOT" -mindepth 1 -type f -print0)

echo "✨ Quarantine pass complete!"
echo "📍 See: $REPORT"
echo "🔁 Reversible until POST_CLEANUP is tagged."