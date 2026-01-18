param (
    [switch]$Deploy
)

# ===============================
# Skyesoft Deploy Script (v2)
# ===============================

Write-Host '=== Skyesoft Deploy ===' -ForegroundColor Cyan

# --- Machine detection ---
$computer = $env:COMPUTERNAME
if ($computer -eq 'Permit-Computer') {
    $machineRole = 'OFFICE'
} else {
    $machineRole = 'LAPTOP'
}

Write-Host "Machine: $machineRole ($computer)"

# --- Git safety checks ---
Write-Host 'Fetching remote state...'
git fetch origin | Out-Null

$status = git status -sb

if ($status -match 'behind') {
    Write-Error 'Repo is BEHIND Git SoT. Pull required. Deploy blocked.'
    exit 1
}

if ($status -match 'ahead .* behind') {
    Write-Error 'Repo has DIVERGED from Git SoT. Manual resolution required.'
    exit 1
}

if ($status -match 'ahead') {
    Write-Host 'Local commits ahead of Git. Pushing...'
    git push origin main
    if ($LASTEXITCODE -ne 0) {
        Write-Error 'Push failed. Deploy aborted.'
        exit 1
    }
    git fetch origin | Out-Null
}

# --- Clean working tree enforcement ---
$dirty = git status --porcelain
if ($dirty) {
    Write-Error 'Uncommitted changes detected. Commit required before deploy.'
    exit 1
}

Write-Host 'Git state clean and aligned with SoT.'

# --- OFFICE confirmation gate ---
if ($machineRole -eq 'OFFICE' -and $Deploy) {
    $confirm = Read-Host 'You are deploying from OFFICE. Continue? (Y/N)'
    if ($confirm -ne 'Y') {
        Write-Host 'Deploy cancelled.'
        exit 0
    }
}

# --- DRY RUN DEFAULT ---
if (-not $Deploy) {
    Write-Host 'DRY RUN - Deploy step skipped' -ForegroundColor Yellow
    Write-Host 'Use: .\deploy.ps1 -Deploy to push to GoDaddy'
    exit 0
}

# --- GoDaddy deploy ---
Write-Host 'Deploying to GoDaddy...' -ForegroundColor Cyan

$winscp = 'C:\Program Files (x86)\WinSCP\winscp.com'
$script  = 'deploy-winscp.txt'

& $winscp /script=$script

if ($LASTEXITCODE -ne 0) {
    Write-Error 'Deploy failed.'
    exit 1
}

Write-Host 'Deploy completed successfully.' -ForegroundColor Green