# Rollback to the stable OfficeBoard version (v2025.05.05-stable-officeBoard)

Write-Host "🔁 Reverting to stable tag: v2025.05.05-stable-officeBoard"

cd "C:\Users\steve\Documents\skyesoft"

# Ensure you're on the main branch first
git checkout main

# Reset to the tagged version
git reset --hard v2025.05.05-stable-officeBoard

Write-Host "✅ Reverted to stable bulletin board layout."
