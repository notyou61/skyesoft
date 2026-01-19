param(
    [switch]$Commit,
    [switch]$Deploy,
    [switch]$DryRun
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
if ($dirty -and -not $Commit) {
    Write-Error 'Uncommitted changes detected. Commit required before deploy.'
    exit 1
}

Write-Host 'Git state verified against SoT.'

# --- Commit phase ---
if ($Commit) {

    $changed = git status --porcelain
    if (-not $changed) {
        Write-Host 'No changes to commit.' -ForegroundColor Yellow
    }
    else {
        Write-Host 'Changes detected:' -ForegroundColor Cyan
        git status --short

        Write-Host 'Generating commit message via AI...' -ForegroundColor Cyan
        $diff = git diff --stat
        $commitMessage = php scripts/commitNarrator.php "$diff"

        if (-not $commitMessage) {
            Write-Error 'Commit narrator failed. Commit aborted.'
            exit 1
        }

        Write-Host ''
        Write-Host 'Proposed commit message:' -ForegroundColor Green
        Write-Host '--------------------------------'
        Write-Host $commitMessage
        Write-Host '--------------------------------'

        $confirm = Read-Host 'Proceed with commit? (Y/N)'
        if ($confirm -ne 'Y') {
            Write-Host 'Commit cancelled by user.' -ForegroundColor Yellow
            exit 0
        }

        if (-not $DryRun) {
            git add .
            git commit -m "$commitMessage"

            if ($LASTEXITCODE -ne 0) {
                Write-Error 'Git commit failed.'
                exit 1
            }

            git push origin main
            Write-Host 'Commit pushed to Git SoT™.' -ForegroundColor Green
        }
        else {
            Write-Host 'DRY RUN: Commit skipped.' -ForegroundColor Yellow
        }
    }
}

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

$script = Join-Path $PSScriptRoot 'deploy-winscp.txt'

# --- WinSCP auto-discovery ---
$possibleWinScpPaths = @(
    "C:\Program Files (x86)\WinSCP\WinSCP.com",
    "C:\Program Files\WinSCP\WinSCP.com",
    "$env:LOCALAPPDATA\Programs\WinSCP\WinSCP.com"
)

$winscp = $possibleWinScpPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $winscp) {
    Write-Error "WinSCP CLI not found. Install WinSCP with command-line support."
    exit 1
}

Write-Host "Using WinSCP CLI: $winscp" -ForegroundColor DarkGray

if (-not (Test-Path $script)) {
    Write-Error "WinSCP script not found at: $script"
    exit 1
}

# --- Execute deploy ---
& "$winscp" `
  /parameter "LOCALROOT=$localRoot" `
  /script="$script"

if ($LASTEXITCODE -ne 0) {
    Write-Error 'Deploy failed.'
    exit 1
}

Write-Host 'Deploy completed successfully.' -ForegroundColor Green