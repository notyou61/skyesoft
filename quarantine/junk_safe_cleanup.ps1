Write-Host "Skyesoft Safe Cleanup - DRY RUN" -ForegroundColor Cyan

$root = "C:\Users\steve\OneDrive\Documents\skyesoft"
$quarantine = "$root\quarantine"

# Blocklist of files/folders NEVER moved
$keepRootItems = @(
    ".git",
    "quarantine",
    ".gitattributes",
    ".gitignore",
    "filelist.txt",
    "junk_safe_cleanup.ps1"
)

$unknownItems = @()

Get-ChildItem -Path $root -Recurse -Force | ForEach-Object {
    $relativePath = $_.FullName.Substring($root.Length + 1)

    # Check if item is protected
    $isProtected = $keepRootItems | Where-Object {
        $relativePath -like "$_*" -or
        $relativePath -eq $_ -or
        $relativePath.StartsWith("$_\")
    }

    if (-not $isProtected) {
        $unknownItems += $relativePath
    }
}

Write-Host ""
Write-Host "Files/Folders that would be moved to quarantine:" -ForegroundColor Yellow
$unknownItems | Out-Host

Write-Host ""
Write-Host "Total unknown items: $($unknownItems.Count)" -ForegroundColor Green
Write-Host "No changes made - this was a DRY RUN only." -ForegroundColor Cyan
