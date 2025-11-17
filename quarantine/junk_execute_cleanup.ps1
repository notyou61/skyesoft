Write-Host "Skyesoft Cleanup – EXECUTION MODE" -ForegroundColor Red

$root = "C:\Users\steve\OneDrive\Documents\skyesoft"
$quarantine = "$root\quarantine"

if (-not (Test-Path $quarantine)) {
    New-Item -ItemType Directory -Path $quarantine | Out-Null
    Write-Host "Created quarantine folder." -ForegroundColor Green
}

# Explicit known delete removals
$deleteTargets = @(
    "$root\legacy",
    "$root\libs\tcpdf"
)

foreach ($item in $deleteTargets) {
    if (Test-Path $item) {
        Write-Host "Deleting: $item" -ForegroundColor Yellow
        Remove-Item -Recurse -Force $item
    }
}

# Blocklist – guaranteed keep
$keepRootItems = @(
    ".git",
    "quarantine",
    ".gitattributes",
    ".gitignore",
    "filelist.txt",
    "junk_safe_cleanup.ps1",
    "junk_execute_cleanup.ps1",
    "assets",
    "api",
    "bulletinBoards",
    "reports",
    "scripts",
    "vendor",
    "logs",
    "index.html",
    "officeBoard.html"
)

$unknownItems = @()

Get-ChildItem -Path $root -Recurse -Force | ForEach-Object {
    $relativePath = $_.FullName.Substring($root.Length + 1)

    $isProtected = $keepRootItems | Where-Object {
        $relativePath -like "$_*" -or
        $relativePath -eq $_ -or
        $relativePath.StartsWith("$_\")
    }

    if (-not $isProtected) {
        $unknownItems += $_
    }
}

Write-Host "Moving unknown items to quarantine for review..." -ForegroundColor Cyan

foreach ($file in $unknownItems) {
    $relativePath = $file.FullName.Substring($root.Length + 1)
    $target = Join-Path -Path $quarantine -ChildPath $relativePath

    New-Item -ItemType Directory -Path (Split-Path $target) -ErrorAction SilentlyContinue | Out-Null
    Move-Item -Path $file.FullName -Destination $target -Force
}

Write-Host "Cleanup complete!" -ForegroundColor Green
Write-Host "Review quarantine folder & commit changes when satisfied." -ForegroundColor Yellow
