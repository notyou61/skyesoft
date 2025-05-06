# Update the stable OfficeBoard version tag (v2025.05.05-stable-officeBoard)

Write-Host "🔖 Re-tagging current commit as v2025.05.05-stable-officeBoard..."

cd "C:\Users\steve\Documents\skyesoft"

# Delete the old tag (both locally and remotely)
git tag -d v2025.05.05-stable-officeBoard
git push origin :refs/tags/v2025.05.05-stable-officeBoard

# Create and push a new tag at the current commit
git tag v2025.05.05-stable-officeBoard
git push origin v2025.05.05-stable-officeBoard

Write-Host "✅ Stable tag updated to current commit."