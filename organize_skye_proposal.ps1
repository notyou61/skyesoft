
# PowerShell Script: organize_skye_proposal.ps1

Write-Host "Starting Skyesoft project file organization..." -ForegroundColor Cyan

# Create folders if they don't exist
$folders = @(
    "docs\proposal\json",
    "docs\proposal\markdown",
    "docs\proposal\html",
    "docs\proposal\pdf",
    "docs\viewer"
)

foreach ($folder in $folders) {
    if (-not (Test-Path $folder)) {
        New-Item -ItemType Directory -Path $folder -Force
    }
}

# Move JSON files
Move-Item -Force "docs\proposal\json\leadership_lead_or_sell_v1.1.json" "docs\proposal\json\"
Move-Item -Force "docs\proposal\json\skyesoft_proposal_final_v1.1.json" "docs\proposal\json\"
Move-Item -Force "docs\proposal\json\index.json" "docs\proposal\json\"

# Move Markdown
if (Test-Path "$env:USERPROFILE\Downloads\leadership_lead_or_sell_v1.1_iconic.md") {
    Move-Item -Force "$env:USERPROFILE\Downloads\leadership_lead_or_sell_v1.1_iconic.md" "docs\proposal\markdown\"
}

# Move HTML
if (Test-Path "docs\proposal\viewer\viewer.html") {
    Move-Item -Force "docs\proposal\viewer\viewer.html" "docs\proposal\html\leadership_lead_or_sell_v1.1.html"
}

# Move PDF
if (Test-Path "$env:USERPROFILE\Downloads\leadership_lead_or_sell_v1.1.pdf") {
    Move-Item -Force "$env:USERPROFILE\Downloads\leadership_lead_or_sell_v1.1.pdf" "docs\proposal\pdf\"
}

# Move Viewer Assets
if (Test-Path "docs\proposal\viewer\proposal.css") {
    Move-Item -Force "docs\proposal\viewer\proposal.css" "docs\viewer\"
}

# Clean up viewer folder if it's empty
if (Test-Path "docs\proposal\viewer") {
    $files = Get-ChildItem "docs\proposal\viewer"
    if ($files.Count -eq 0) {
        Remove-Item "docs\proposal\viewer" -Force -Recurse
    }
}

Write-Host "✅ File organization complete." -ForegroundColor Green
