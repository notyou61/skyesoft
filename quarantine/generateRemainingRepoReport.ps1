# 📌 Generate Remaining Repo Inventory Report (excluding quarantine)

$repoRoot = "C:\Users\steve\OneDrive\Documents\skyesoft"
$timestamp = (Get-Date -Format "yyyy-MM-dd_HH-mm-ss")
$outputTxt = Join-Path $repoRoot "repo_remaining_$timestamp.txt"
$outputJson = Join-Path $repoRoot "repo_remaining_$timestamp.json"

Write-Host "Scanning active repo..." -ForegroundColor Cyan

$remainingItems = Get-ChildItem -Path $repoRoot -Recurse -Force |
    Where-Object {
        $_.FullName -notlike "*\quarantine\*" -and
        $_.FullName -notmatch "\\\.git(\\|$)"
    } |
    Select-Object FullName, PSIsContainer

# Save text report
$remainingItems | Select-Object FullName |
    Out-File -Encoding utf8 $outputTxt

# Save JSON report
$remainingItems | ConvertTo-Json -Depth 10 |
    Out-File -Encoding utf8 $outputJson

Write-Host "`nReport Generated:" -ForegroundColor Green
Write-Host "TXT → $outputTxt"
Write-Host "JSON → $outputJson"

Write-Host "`nItems Found:" -ForegroundColor Yellow
Write-Host $remainingItems.Count

Write-Host "`nDone!"
